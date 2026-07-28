<?php

namespace App\Services\Import;

use App\Models\Branch;
use App\Repositories\Import\ClinicServiceImportRepository;
use App\Support\Import\ClinicServiceJsonMapper;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class ClinicServiceJsonImportService
{
    public function __construct(
        private readonly ClinicServiceImportRepository $repository,
        private readonly ClinicServiceJsonMapper $mapper,
    ) {}

    public function eligibleBranch(int $branchId): ?Branch
    {
        $branch = $this->repository->findBranch($branchId);

        if ($branch === null || ! $branch->is_active || ! $branch->supportsClinic()) {
            return null;
        }

        return $branch;
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeFile(string $path, Branch $branch): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Source file is missing or unreadable.');
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException('Source file could not be read.');
        }

        return $this->analyzeJson($json, $branch);
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeJson(string $json, Branch $branch): array
    {
        try {
            $records = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'Source JSON is invalid: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_array($records) || ! array_is_list($records)) {
            throw new UnexpectedValueException('Source JSON root must be an array.');
        }

        $durationSources = [
            'numeric' => 0,
            'safely_parsed' => 0,
            'range' => 0,
            'unparseable' => 0,
        ];
        $seenSourceIds = [];
        $seenSlugs = [];
        $duplicateSourceIds = 0;
        $duplicateSlugs = 0;
        $valid = [];
        $quarantined = [];
        $failed = [];

        foreach ($records as $index => $record) {
            if (! is_array($record) || array_is_list($record)) {
                $durationSources['unparseable']++;
                $failed[] = [
                    'source_id' => '#'.($index + 1),
                    'reason' => 'record_must_be_an_object',
                ];

                continue;
            }

            $mapped = $this->mapper->map($record);
            $durationSource = (string) ($mapped['duration_source'] ?? 'unparseable');
            $durationSources[$durationSource] = ($durationSources[$durationSource] ?? 0) + 1;

            if ($mapped['status'] === 'failed') {
                $failed[] = $this->issueSummary($mapped);

                continue;
            }

            $sourceId = (string) $mapped['source_id'];
            $slug = (string) $mapped['slug'];
            $duplicateReasons = [];

            if (isset($seenSourceIds[$sourceId])) {
                $duplicateSourceIds++;
                $duplicateReasons[] = 'duplicate_source_id';
            } else {
                $seenSourceIds[$sourceId] = true;
            }

            if (isset($seenSlugs[$slug])) {
                $duplicateSlugs++;
                $duplicateReasons[] = 'duplicate_slug';
            } else {
                $seenSlugs[$slug] = true;
            }

            if ($duplicateReasons !== []) {
                $failed[] = [
                    'source_id' => $sourceId,
                    'reason' => implode(',', $duplicateReasons),
                ];

                continue;
            }

            if ($mapped['status'] === 'quarantined') {
                $quarantined[] = $this->issueSummary($mapped);

                continue;
            }

            $valid[] = $mapped;
        }

        $slugs = array_map(
            static fn (array $item): string => (string) $item['slug'],
            $valid,
        );
        $existing = $this->repository->findServicesBySlugs($slugs)->keyBy('slug');
        $existingIds = $existing->pluck('id')->map(
            static fn (mixed $id): int => (int) $id,
        )->all();
        $attachedIds = array_flip(
            $this->repository->attachedServiceIds($branch->id, $existingIds),
        );
        $plannedInserts = 0;
        $plannedUpdates = 0;
        $plannedAttachments = 0;
        $samples = [];

        foreach ($valid as $item) {
            $service = $existing->get((string) $item['slug']);
            $operation = $service === null ? 'insert' : 'update';
            $needsAttachment = $service === null || ! isset($attachedIds[(int) $service->id]);

            $operation === 'insert' ? $plannedInserts++ : $plannedUpdates++;
            $plannedAttachments += (int) $needsAttachment;

            if (count($samples) < 5) {
                /** @var array<string, mixed> $attributes */
                $attributes = $item['service'];
                $samples[] = [
                    'source_id' => $item['source_id'],
                    'sku' => $item['sku'],
                    'operation' => $operation,
                    'slug' => $item['slug'],
                    'name' => $attributes['name'],
                    'duration' => $attributes['duration_minutes'],
                    'price' => $attributes['price'],
                    'category' => $attributes['category'],
                    'attach' => $needsAttachment ? 'yes' : 'no',
                ];
            }
        }

        $quarantinedByReason = [];

        foreach ($quarantined as $issue) {
            $quarantinedByReason[$issue['reason']][] = $issue['source_id'];
        }

        return [
            'total' => count($records),
            'planned_inserts' => $plannedInserts,
            'planned_updates' => $plannedUpdates,
            'quarantined' => count($quarantined),
            'failed' => count($failed),
            'duplicate_source_ids' => $duplicateSourceIds,
            'duplicate_slugs' => $duplicateSlugs,
            'planned_branch_attachments' => $plannedAttachments,
            'duration_sources' => $durationSources,
            'planned_samples' => $samples,
            'quarantine_examples' => array_slice($quarantined, 0, 5),
            'quarantined_by_reason' => $quarantinedByReason,
            'failure_examples' => array_slice($failed, 0, 5),
            'service_upsert_key' => 'slug',
            'branch_service_key' => 'branch_id+service_id',
            'default_capacity' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array{source_id: string, reason: string}
     */
    private function issueSummary(array $mapped): array
    {
        return [
            'source_id' => (string) ($mapped['source_id'] ?: '(missing)'),
            'reason' => (string) $mapped['reason'],
        ];
    }
}
