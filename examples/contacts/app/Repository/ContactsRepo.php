<?php

declare(strict_types=1);

namespace App\Repository;

use Cloude\Collection;
use Cloude\Data\JsonRepository;
use Cloude\Str;

/**
 * Contacts repository: one .json file per contact under $baseDir.
 *
 *   $baseDir/{slug}.json   →   {"name": ..., "email": ..., ...}
 *
 * Uses the framework's JsonRepository so we get atomic writes (via
 * JsonFile::write) and a per-request read cache for free.
 */
class ContactsRepo extends JsonRepository
{
    public function __construct(string $baseDir)
    {
        parent::__construct($baseDir);
    }

    /**
     * Normalize raw disk shape into a stable in-memory shape. The slug is
     * attached so it survives pluck() / groupBy() downstream.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    protected function transform(array $data, string $slug): array
    {
        return [
            'slug'       => $slug,
            'name'       => (string) ($data['name']       ?? $slug),
            'email'      => (string) ($data['email']      ?? ''),
            'phone'      => (string) ($data['phone']      ?? ''),
            'notes'      => (string) ($data['notes']      ?? ''),
            'created_at' => $data['created_at'] ?? null,
        ];
    }

    /**
     * find() with transform applied. Use this for detail pages / API output —
     * the parent's find() returns the raw on-disk shape.
     *
     * @return array<mixed>|null
     */
    public function findOne(string $slug): ?array
    {
        $data = $this->find($slug);
        return $data === null ? null : $this->transform($data, $slug);
    }

    /**
     * Returns contacts whose name, email or notes contain $query, case- and
     * accent-insensitive. Empty query returns every contact, sorted by name.
     */
    public function search(string $query): Collection
    {
        $needle = Str::ascii(mb_strtolower(trim($query)));
        $list   = $this->all()->sortBy('name');

        if ($needle === '') {
            return $list;
        }

        return $list->filter(static function (array $c) use ($needle): bool {
            $hay = Str::ascii(mb_strtolower(
                $c['name'] . ' ' . $c['email'] . ' ' . $c['notes']
            ));
            return str_contains($hay, $needle);
        });
    }

    /**
     * Picks a slug for $name that does not collide with an existing entity.
     * "John Doe" → "john-doe", or "john-doe-2", "john-doe-3", … if taken.
     */
    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = Str::random(4);
        }
        $slug = $base;
        $i = 2;
        while ($this->exists($slug)) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Removes the underlying .json file. Returns true when something was
     * actually deleted.
     */
    public function delete(string $slug): bool
    {
        $file = $this->baseDir . '/' . $slug . '.json';
        if (!is_file($file)) {
            return false;
        }
        return @unlink($file);
    }
}
