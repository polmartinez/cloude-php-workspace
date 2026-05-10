<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Model\Storage\MarkdownStorage;
use PHPUnit\Framework\TestCase;

final class MarkdownStorageTest extends TestCase
{
    private string $dir;
    private MarkdownStorage $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cloude-md-storage-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0755, true);
        $this->storage = new MarkdownStorage($this->dir, primaryKey: 'slug');

        file_put_contents($this->dir . '/intro.md', "---\ntitle: Hello\nauthor: Ada\n---\n\nThis is the body.\n");
        file_put_contents($this->dir . '/skip.md', "Plain body, no frontmatter.\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testFindParsesFrontmatterAndBody(): void
    {
        $row = $this->storage->find('intro');
        self::assertNotNull($row);
        self::assertSame('Hello', $row['meta']['title']);
        self::assertSame('Ada', $row['meta']['author']);
        self::assertStringContainsString('This is the body', $row['raw']);
        self::assertStringContainsString('<p>This is the body', $row['html']);
        self::assertSame('intro', $row['slug'], 'PK injected on read');
    }

    public function testFindOnFrontmatterlessFile(): void
    {
        $row = $this->storage->find('skip');
        self::assertNotNull($row);
        self::assertSame([], $row['meta']);
        self::assertStringContainsString('Plain body', $row['raw']);
    }

    public function testFindReturnsNullForMissing(): void
    {
        self::assertNull($this->storage->find('nope'));
    }

    public function testRawReturnsFileContentAsIs(): void
    {
        $raw = $this->storage->raw('intro');
        self::assertStringContainsString('---', $raw);                              // includes frontmatter
        self::assertStringContainsString('title: Hello', $raw);
        self::assertStringContainsString('This is the body', $raw);
    }

    public function testCountCountsFiles(): void
    {
        self::assertSame(2, $this->storage->count());
    }

    public function testFindByOnFrontmatterField(): void
    {
        $rows = $this->storage->findBy(['title' => 'Hello']);
        self::assertCount(1, $rows);
        self::assertSame('intro', $rows[0]['slug']);
    }

    public function testInsertSerialisesFrontmatterPlusBody(): void
    {
        $id = $this->storage->insert([
            'slug' => 'new-post',
            'meta' => ['title' => 'New', 'lang' => 'es'],
            'raw'  => "Body of the new post.\n",
        ]);
        self::assertSame('new-post', $id);

        // Cloude\Markdown\File::write always stores as .md.gz — read back via
        // the framework helper that handles the gz transparently.
        $disk = \Cloude\Markdown\File::read($this->dir . '/new-post.md');
        self::assertStringContainsString('title: New', $disk);
        self::assertStringContainsString('lang: es', $disk);
        self::assertStringContainsString('Body of the new post', $disk);

        // Round-trip through find()
        $row = $this->storage->find('new-post');
        self::assertSame('New', $row['meta']['title']);
    }

    public function testUpdateMergesMeta(): void
    {
        $this->storage->update('intro', ['meta' => ['author' => 'Ada Lovelace']]);
        $row = $this->storage->find('intro');
        self::assertSame('Ada Lovelace', $row['meta']['author']);
    }

    public function testDeleteRemovesFile(): void
    {
        self::assertTrue($this->storage->delete('intro'));
        self::assertNull($this->storage->find('intro'));
        self::assertFalse($this->storage->delete('intro'));         // already gone
    }

    public function testGzippedFileTransparency(): void
    {
        // Replace intro.md with a gzipped variant
        @unlink($this->dir . '/intro.md');
        file_put_contents(
            $this->dir . '/intro.md.gz',
            gzencode("---\ntitle: Gzipped\n---\n\nGzipped body.\n"),
        );

        $row = $this->storage->find('intro');
        self::assertNotNull($row, 'should read .md.gz transparently');
        self::assertSame('Gzipped', $row['meta']['title']);
        self::assertSame(2, $this->storage->count());                // both .md and .md.gz files count
    }
}
