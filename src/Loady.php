<?php 

namespace Loady;

require 'Finder.php';

use SplFileInfo;
use Loady\FileInfo;

/**
 * Class Loady
 * @package Loady
 * @author Allen Doctor <thedoctorisin17@gmail.com>
 */
Class Loady
{

    const DS = DIRECTORY_SEPARATOR;
    
    const retryLimit = 3;

    /** @var string[] */
    public $ignoreDirs = ['.*', '*.old', '*.bak', '*.tmp', 'temp'];

    /** @var string[] */
    public array $acceptFiles = ['*.php'];
    private bool $autoRebuild = true;
    private bool $reportParseErrors = true;

    /** @var string[] */
    private array $scanPaths = [];

    /** @var string[] */
    private array $loadFiles = [];

    /** @var string[] */
    private array $excludeDirs = [];

    /** @var array<string, array{string, int}>  class => [file, time] */
    private array $classes = [];
    private bool $cacheLoaded = false;
    private bool $refreshed = false;

    /** @var array<string, int>  class => counter */
    private array $missingClasses = [];

    /** @var array<string, int>  file => mtime */
    private array $emptyFiles = [];
    private ?string $tempDirectory = null;
    private bool $needSave = false;

    public function __construct()
    {
        if (!extension_loaded('tokenizer')) {
            throw new \RuntimeException('PHP extension Tokenizer is not loaded.');
        }
    }


    public function __destruct()
    {
        if ($this->needSave) {
            $this->saveCache();
        }
    }


    /**
     * Register autoloader.
     */
    public function register(bool $prepend = false): static
    {
        $this->tryLoadNonClass();

        spl_autoload_register([$this, 'tryLoad'], true, $prepend);
        
        return $this;
    }

    protected function tryLoadNonClass()
    {
        if (empty($this->loadFiles)) {
            return;
        }
        
        foreach ($this->loadFiles as $file) {

            $file = static::unixSlashes($file);

            if (! file_exists($file)) {
                continue;
            }

            $pathInfo = pathinfo($file);

            $this->tryLoad($pathInfo['filename']);
        }
    }


    /**
     * Handles autoloading of classes, interfaces or traits.
     */
    public function tryLoad(string $type, string $path = null): void
    {
        
        $this->loadCache();

        $missing = $this->missingClasses[$type] ?? null;
        if ($missing >= self::retryLimit) {
            return;
        }

        [$file, $mtime] = $this->classes[$type] ?? null;

        if ($this->autoRebuild) {
            if (!$this->refreshed) {
                if (!$file || !is_file($file)) {
                    $this->refreshClasses();
                    [$file] = $this->classes[$type] ?? null;
                    $this->needSave = true;

                } elseif (filemtime($file) !== $mtime) {
                    $this->updateFile($file);
                    [$file] = $this->classes[$type] ?? null;
                    $this->needSave = true;
                }
            }

            if (!$file || !is_file($file)) {
                $this->missingClasses[$type] = ++$missing;
                $this->needSave = $this->needSave || $file || ($missing <= self::retryLimit);
                unset($this->classes[$type]);
                $file = null;
            }
        }

        if ($file) {
            (static function ($file) { require $file; })($file);
        }
    }


    /**
     * Add path or paths to list.
     */
    public function addDirectory(string ...$paths): static
    {
        $this->scanPaths = array_merge($this->scanPaths, $paths);
        return $this;
    }

    public function addFiles(string ...$files): static
    {
        $this->loadFiles = array_merge($this->loadFiles, $files);
        return $this;
    }


    public function reportParseErrors(bool $on = true): static
    {
        $this->reportParseErrors = $on;
        return $this;
    }


    /**
     * Excludes path or paths from list.
     */
    public function excludeDirectory(string ...$paths): static
    {
        $this->excludeDirs = array_merge($this->excludeDirs, $paths);
        return $this;
    }


    /**
     * @return array<string, string>  class => filename
     */
    public function getIndexedClasses(): array
    {
        $this->loadCache();
        $res = [];
        foreach ($this->classes as $class => [$file]) {
            $res[$class] = $file;
        }

        return $res;
    }


    /**
     * Rebuilds class list cache.
     */
    public function rebuild(): void
    {
        $this->cacheLoaded = true;
        $this->classes = $this->missingClasses = $this->emptyFiles = [];
        $this->refreshClasses();
        if ($this->tempDirectory) {
            $this->saveCache();
        }
    }


    /**
     * Refreshes class list cache.
     */
    public function refresh(): void
    {
        $this->loadCache();
        if (!$this->refreshed) {
            $this->refreshClasses();
            $this->saveCache();
        }
    }


    /**
     * Refreshes $this->classes & $this->emptyFiles.
     */
    private function refreshClasses(): void
    {
        $this->refreshed = true; // prevents calling refreshClasses() or updateFile() in tryLoad()
        $files = $this->emptyFiles;
        $classes = [];
        foreach ($this->classes as $class => [$file, $mtime]) {
            $files[$file] = $mtime;
            $classes[$file][] = $class;
        }

        $this->classes = $this->emptyFiles = [];

        foreach ($this->scanPaths as $path) {
            $iterator = is_file($path)
                ? [new SplFileInfo($path)]
                : $this->createFileIterator($path);

            foreach ($iterator as $fileInfo) {
                $mtime = $fileInfo->getMTime();
                $file = $fileInfo->getPathname();
                $foundClasses = isset($files[$file]) && $files[$file] === $mtime
                    ? ($classes[$file] ?? [])
                    : $this->scanPhp($file);

                if (!$foundClasses) {
                    $this->emptyFiles[$file] = $mtime;
                }

                $files[$file] = $mtime;
                $classes[$file] = []; // prevents the error when adding the same file twice

                foreach ($foundClasses as $class) {
                    if (isset($this->classes[$class])) {
                        throw new \RuntimeException(sprintf(
                            'Ambiguous class %s resolution; defined in %s and in %s.',
                            $class,
                            $this->classes[$class][0],
                            $file,
                        ));
                    }

                    $this->classes[$class] = [$file, $mtime];
                    unset($this->missingClasses[$class]);
                }
            }
        }

        foreach ($this->loadFiles as $file) {

            $file = static::unixSlashes($file);

            if (! file_exists($file)) {
                continue;
            }

            $iterator = [new SplFileInfo($file)];

            foreach ($iterator as $fileInfo) {
                $mtime = $fileInfo->getMTime();
                $file = $fileInfo->getPathname();
                $foundClasses = isset($files[$file]) && $files[$file] === $mtime
                    ? ($classes[$file] ?? [])
                    : $this->scanPhp($file, $file);

                if (!$foundClasses) {
                    $this->emptyFiles[$file] = $mtime;
                }

                $files[$file] = $mtime;
                $classes[$file] = [];
                foreach ($foundClasses as $class) {
                    if (isset($this->classes[$class])) {
                        throw new \RuntimeException(sprintf(
                            'Ambiguous file %s resolution; defined in %s and in %s.',
                            $class,
                            $this->classes[$class][0],
                            $file,
                        ));
                    }

                    $this->classes[$class] = [$file, $mtime];
                    unset($this->missingClasses[$class]);
                }
            }
            
        }
    }

    public static function unixSlashes(string $path): string
    {
        return strtr($path, '\\', '/');
    }

    public static function getLastError(): string
    {
        $message = error_get_last()['message'] ?? '';
        $message = preg_replace('#^\w+\(.*?\): #', '', $message);
        return $message;
    }

    public static function createDir(string $dir, int $mode = 0777): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) { // @ - dir may already exist
            throw new InvalidArgumentException(sprintf(
                "Unable to create directory '%s' with mode %s. %s",
                self::normalizePath($dir),
                decoct($mode),
                static::getLastError(),
            ));
        }
    }


    /**
     * Creates an iterator scanning directory for PHP files and subdirectories.
     * @throws Nette\IOException if path is not found
     */
    private function createFileIterator(string $dir): \Loady\Finder
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException(sprintf("File or directory '%s' not found.", $dir));
        }

        $dir = realpath($dir) ?: $dir; // realpath does not work in phar
        $disallow = [];
        foreach (array_merge($this->ignoreDirs, $this->excludeDirs) as $item) {
            if ($item = realpath($item)) {
                $disallow[static::unixSlashes($item)] = true;
            }
        }

        $filter = function(SplFileInfo $file) use($disallow) {
            return $file->getRealPath() === false
                    || ! isset($disallow[static::unixSlashes($file->getRealPath())]);
        };

        $iterator = \Loady\Finder::findFiles($this->acceptFiles)
            ->filter($filter)
            ->descentFilter($filter)
            ->from($dir)
            ->exclude($this->ignoreDirs);

        return $iterator;
    }


    private function updateFile(string $file): void
    {
        foreach ($this->classes as $class => [$prevFile]) {
            if ($file === $prevFile) {
                unset($this->classes[$class]);
            }
        }

        $foundClasses = is_file($file) ? $this->scanPhp($file) : [];

        foreach ($foundClasses as $class) {
            [$prevFile, $prevMtime] = $this->classes[$class] ?? null;

            if (isset($prevFile) && @filemtime($prevFile) !== $prevMtime) { // @ file may not exists
                $this->updateFile($prevFile);
                [$prevFile] = $this->classes[$class] ?? null;
            }

            if (isset($prevFile)) {
                throw new \InvalidArgumentException(sprintf(
                    'Ambiguous class %s resolution; defined in %s and in %s.',
                    $class,
                    $prevFile,
                    $file,
                ));
            }

            $this->classes[$class] = [$file, filemtime($file)];
        }
    }


    /**
     * Searches classes, interfaces and traits in PHP file.
     * @return string[]
     */
    private function scanPhp(string $file, string $forFile = ''): array
    {
        $code = file_get_contents($file);
        $expected = false;
        $namespace = $name = '';
        $level = $minLevel = 0;
        $classes = [];

        try {
            $tokens = \PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            if ($this->reportParseErrors) {
                $rp = new \ReflectionProperty($e, 'file');
                $rp->setAccessible(true);
                $rp->setValue($e, $file);
                throw $e;
            }

            $tokens = [];
        }

        foreach ($tokens as $token) {
            switch ($token->id) {
                case T_COMMENT:
                case T_DOC_COMMENT:
                case T_WHITESPACE:
                    continue 2;

                case T_STRING:
                case T_NAME_QUALIFIED:
                    if ($expected) {
                        $name .= $token->text;
                    }

                    continue 2;

                case T_NAMESPACE:
                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case PHP_VERSION_ID < 80100
                    ? T_CLASS
                    : T_ENUM:
                    $expected = $token->id;
                    $name = '';
                    continue 2;

                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $level++;
            }

            if ($expected) {
                if ($expected === T_NAMESPACE) {
                    $namespace = $name ? $name . '\\' : '';
                    $minLevel = $token->text === '{' ? 1 : 0;

                } elseif ($name && $level === $minLevel) {

                    $classes[] = $namespace . $name;
                }

                $expected = null;
            } else {
                if (! empty($forFile) && $file == $forFile) {

                    if (file_exists($forFile)) {
                        $pathInfo = pathinfo($forFile);

                        $classes[] = $pathInfo['filename'];
                        $classes = array_unique($classes);
                    }
                }
            }

            if ($token->text === '{') {
                $level++;
            } elseif ($token->text === '}') {
                $level--;
            }
        }

        return $classes;
    }


    /********************* caching ****************d*g**/


    /**
     * Sets auto-refresh mode.
     */
    public function setAutoRefresh(bool $on = true): static
    {
        $this->autoRebuild = $on;
        return $this;
    }


    /**
     * Sets path to temporary directory.
     */
    public function setTempDirectory(string $dir): static
    {
        static::createDir($dir);
        $this->tempDirectory = $dir;
        return $this;
    }


    /**
     * Loads class list from cache.
     */
    private function loadCache(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        $this->cacheLoaded = true;

        $file = $this->generateCacheFileName();

        // Solving atomicity to work everywhere is really pain in the ass.
        // 1) We want to do as little as possible IO calls on production and also directory and file can be not writable (#19)
        // so on Linux we include the file directly without shared lock, therefore, the file must be created atomically by renaming.
        // 2) On Windows file cannot be renamed-to while is open (ie by include() #11), so we have to acquire a lock.
        $lock = defined('PHP_WINDOWS_VERSION_BUILD')
            ? $this->acquireLock("$file.lock", LOCK_SH)
            : null;

        $data = @include $file; // @ file may not exist
        if (is_array($data)) {
            [$this->classes, $this->missingClasses, $this->emptyFiles] = $data;
            return;
        }

        if ($lock) {
            flock($lock, LOCK_UN); // release shared lock so we can get exclusive
        }

        $lock = $this->acquireLock("$file.lock", LOCK_EX);

        // while waiting for exclusive lock, someone might have already created the cache
        $data = @include $file; // @ file may not exist
        if (is_array($data)) {
            [$this->classes, $this->missingClasses, $this->emptyFiles] = $data;
            return;
        }

        $this->classes = $this->missingClasses = $this->emptyFiles = [];
        $this->refreshClasses();
        $this->saveCache($lock);
        // On Windows concurrent creation and deletion of a file can cause a 'permission denied' error,
        // therefore, we will not delete the lock file. Windows is really annoying.
    }


    /**
     * Writes class list to cache.
     * @param  resource  $lock
     */
    private function saveCache($lock = null): void
    {
        // we have to acquire a lock to be able safely rename file
        // on Linux: that another thread does not rename the same named file earlier
        // on Windows: that the file is not read by another thread
        $file = $this->generateCacheFileName();
        $lock = $lock ?: $this->acquireLock("$file.lock", LOCK_EX);
        $code = "<?php\nreturn " . var_export([$this->classes, $this->missingClasses, $this->emptyFiles], true) . ";\n";

        if (file_put_contents("$file.tmp", $code) !== strlen($code) || !rename("$file.tmp", $file)) {
            @unlink("$file.tmp"); // @ file may not exist
            throw new \RuntimeException(sprintf("Unable to create '%s'.", $file));
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true); // @ can be restricted
        }
    }


    /** @return resource */
    private function acquireLock(string $file, int $mode)
    {
        $handle = @fopen($file, 'w'); // @ is escalated to exception
        if (!$handle) {
            throw new \RuntimeException(sprintf("Unable to create file '%s'. %s", $file, error_get_last()['message']));
        } elseif (!@flock($handle, $mode)) { // @ is escalated to exception
            throw new \RuntimeException(sprintf(
                "Unable to acquire %s lock on file '%s'. %s",
                $mode & LOCK_EX ? 'exclusive' : 'shared',
                $file,
                error_get_last()['message'],
            ));
        }

        return $handle;
    }


    private function generateCacheFileName(): string
    {
        if (!$this->tempDirectory) {
            throw new \LogicException('Set path to temporary directory using setTempDirectory().');
        }

        return $this->tempDirectory . '/' . md5(serialize($this->generateCacheKey())) . '.php';
    }


    protected function generateCacheKey(): array
    {
        return [$this->ignoreDirs, $this->acceptFiles, $this->scanPaths, $this->excludeDirs, 'v2'];
    }
}

