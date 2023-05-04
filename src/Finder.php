<?php namespace Loady;

require_once 'FileInfo.php';

use Loady\FileInfo;

/**
 * Class Finder
 * @package Loady
 * @author Allen Doctor <thedoctorisin17@gmail.com>
 */
Class Finder implements \IteratorAggregate
{
     /** @var array<array{string, string}> */
    private array $find = [];

    /** @var string[] */
    private array $in = [];

    /** @var \Closure[] */
    private array $filters = [];

    /** @var \Closure[] */
    private array $descentFilters = [];

    /** @var array<string|self> */
    private array $appends = [];
    private bool $childFirst = false;

    /** @var ?callable */
    private $sort;
    private int $maxDepth = -1;
    private bool $ignoreUnreadableDirs = true;

    public static function findFiles(string|array $masks = ['*']): static
    {
        $masks = is_array($masks) ? $masks : func_get_args(); // compatibility with variadic
        return (new static)->addMask($masks, 'file');
    }

    private function addMask(array $masks, string $mode): static
    {
        foreach ($masks as $mask) {
            $orig = $mask;
            if ($mode === 'dir') {
                $mask = rtrim($mask, '/\\');
            }

            if ($mask === '' || ($mode === 'file' && $mask !== $orig)) {
                throw new \InvalidArgumentException("Invalid mask '$mask'");
            }

            $mask = preg_replace('~\*\*[/\\\\]~A', '', $mask);
            $this->find[] = [$mask, $mode];
        }

        return $this;
    }

    public function filter(callable $callback): static
    {
        $this->filters[] = \Closure::fromCallable($callback);
        return $this;
    }

    public function from(string|array $paths): static
    {
        $paths = is_array($paths) ? $paths : func_get_args(); // compatibility with variadic
        $this->addLocation($paths, DIRECTORY_SEPARATOR . '**');
        return $this;
    }

    private function addLocation(array $paths, string $ext): void
    {
        foreach ($paths as $path) {
            if ($path === '') {
                throw new \InvalidArgumentException("Invalid directory '$path'");
            }

            $path = rtrim($path, '/\\');
            $this->in[] = $path . $ext;
        }
    }

    public function descentFilter(callable $callback): static
    {
        $this->descentFilters[] = \Closure::fromCallable($callback);
        return $this;
    }

    public function exclude(string|array $masks): static
    {
        $masks = is_array($masks) ? $masks : func_get_args(); // compatibility with variadic
        foreach ($masks as $mask) {
            $mask = static::normalizeSlash($mask);

            if (!preg_match('~^/?(\*\*/)?(.+)(/\*\*|/\*|/|)$~D', $mask, $m)) {
                throw new \InvalidArgumentException("Invalid mask '$mask'");
            }

            $end = $m[3];
            $re = $this->buildPattern($m[2]);

            $filter = function(FileInfo $file) use($end, $re): bool {
                return ($end && !$file->isDir()) 
                        || ! preg_match($re, static::normalizeSlash($file->getRelativePathname()));
            };

            $this->descentFilter($filter);

            if ($end !== '/*') {
                $this->filter($filter);
            }
        }

        return $this;
    }

    private static function startsWith($haystack, $needle): string
    {
        return (string)$needle !== '' && \strncmp($haystack, $needle, \strlen($needle)) === 0;
    }

    private static function normalizeSlash(string $path): string
    {
        return strtr($path, '\\', '/');
    }

    private function buildPattern(string $mask): string
    {
        $mask = static::normalizeSlash($mask);

        if ($mask === '*') {
            return '##';
        } elseif (static::startsWith($mask, './')) {
            $anchor = '^';
            $mask = substr($mask, 2);
        } else {
            $anchor = '(?:^|/)';
        }

        $pattern = strtr(
            preg_quote($mask, '#'),
            [
                '\*\*/' => '(.+/)?',
                '\*' => '[^/]*',
                '\?' => '[^/]',
                '\[\!' => '[^',
                '\[' => '[',
                '\]' => ']',
                '\-' => '-',
            ],
        );

        return '#' . $anchor . $pattern . '$#D' . (defined('PHP_WINDOWS_VERSION_BUILD') ? 'i' : '');
    }

    public static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#([a-z]:)?[/\\\\]|[a-z][a-z0-9+.-]*://#Ai', $path);
    }


    public function collect(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }


    /** @return \Generator<string, FileInfo> */
    public function getIterator(): \Generator
    {
        $plan = $this->buildPlan();
        foreach ($plan as $dir => $searches) {
            yield from $this->traverseDir($dir, $searches);
        }

        foreach ($this->appends as $item) {
            if ($item instanceof self) {
                yield from $item->getIterator();
            } else {
                yield $item => new FileInfo($item);
            }
        }
    }


    /**
     * @param  array<\stdClass{pattern: string, mode: string, recursive: bool}>  $searches
     * @param  string[]  $subdirs
     * @return \Generator<string, FileInfo>
     */
    private function traverseDir(string $dir, array $searches, array $subdirs = []): \Generator
    {
        if ($this->maxDepth >= 0 && count($subdirs) > $this->maxDepth) {
            return;
        } elseif (!is_dir($dir)) {
            throw new \InvalidArgumentException(sprintf("Directory '%s' does not exist.", rtrim($dir, '/\\')));
        }

        try {
            $pathNames = new \FilesystemIterator($dir, \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME);
        } catch (\UnexpectedValueException $e) {
            if ($this->ignoreUnreadableDirs) {
                return;
            } else {
                throw new InvalidArgumentException($e->getMessage());
            }
        }

        $files = $this->convertToFiles($pathNames, implode(DIRECTORY_SEPARATOR, $subdirs), static::isAbsolute($dir));

        if ($this->sort) {
            $files = iterator_to_array($files);
            usort($files, $this->sort);
        }

        foreach ($files as $file) {
            $pathName = $file->getPathname();
            $cache = $subSearch = [];

            if ($file->isDir()) {
                foreach ($searches as $search) {
                    if ($search->recursive && $this->proveFilters($this->descentFilters, $file, $cache)) {
                        $subSearch[] = $search;
                    }
                }
            }

            if ($this->childFirst && $subSearch) {
                yield from $this->traverseDir($pathName, $subSearch, array_merge($subdirs, [$file->getBasename()]));
            }

            $relativePathname = static::normalizeSlash($file->getRelativePathname());
            foreach ($searches as $search) {
                if (
                    $file->getType() === $search->mode
                    && preg_match($search->pattern, $relativePathname)
                    && $this->proveFilters($this->filters, $file, $cache)
                ) {
                    yield $pathName => $file;
                    break;
                }
            }

            if (!$this->childFirst && $subSearch) {
                yield from $this->traverseDir($pathName, $subSearch, array_merge($subdirs, [$file->getBasename()]));
            }
        }
    }


    private function convertToFiles(iterable $pathNames, string $relativePath, bool $absolute): \Generator
    {
        foreach ($pathNames as $pathName) {
            if (!$absolute) {
                $pathName = preg_replace('~\.?[\\\\/]~A', '', $pathName);
            }

            yield new FileInfo($pathName, $relativePath);
        }
    }


    private function proveFilters(array $filters, FileInfo $file, array &$cache): bool
    {
        foreach ($filters as $filter) {
            $res = &$cache[spl_object_id($filter)];
            $res ??= $filter($file);
            if (!$res) {
                return false;
            }
        }

        return true;
    }


    /** @return array<string, array<\stdClass{pattern: string, mode: string, recursive: bool}>> */
    private function buildPlan(): array
    {
        $plan = $dirCache = [];
        foreach ($this->find as [$mask, $mode]) {
            $splits = [];
            if (static::isAbsolute($mask)) {
                if ($this->in) {
                    throw new \InvalidArgumentException("You cannot combine the absolute path in the mask '$mask' and the directory to search '{$this->in[0]}'.");
                }
                $splits[] = self::splitRecursivePart($mask);
            } else {
                foreach ($this->in ?: ['.'] as $in) {
                    $in = strtr($in, ['[' => '[[]', ']' => '[]]']); // in path, do not treat [ and ] as a pattern by glob()
                    $splits[] = self::splitRecursivePart($in . DIRECTORY_SEPARATOR . $mask);
                }
            }

            foreach ($splits as [$base, $rest, $recursive]) {
                $base = $base === '' ? '.' : $base;
                $dirs = $dirCache[$base] ??= strpbrk($base, '*?[')
                    ? glob($base, GLOB_NOSORT | GLOB_ONLYDIR | GLOB_NOESCAPE)
                    : [strtr($base, ['[[]' => '[', '[]]' => ']'])]; // unescape [ and ]

                if (!$dirs) {
                    throw new \InvalidArgumentException(sprintf("Directory '%s' does not exist.", rtrim($base, '/\\')));
                }

                $search = (object) ['pattern' => $this->buildPattern($rest), 'mode' => $mode, 'recursive' => $recursive];
                foreach ($dirs as $dir) {
                    $plan[$dir][] = $search;
                }
            }
        }

        return $plan;
    }


    /**
     * Since glob() does not know ** wildcard, we divide the path into a part for glob and a part for manual traversal.
     */
    private static function splitRecursivePart(string $path): array
    {
        preg_match('~(.*[\\\\/])(.*)$~A', $path, $m);
        $parts = preg_split('~(?<=^|[\\\\/])\*\*($|[\\\\/])~', $m[1], 2);
        return isset($parts[1])
            ? [$parts[0], $parts[1] . $m[2], true]
            : [$parts[0], $m[2], false];
    }
}