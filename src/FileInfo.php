<?php namespace Loady;

/**
 * Class FileInfo
 * @package Loady
 * @author Allen Doctor <thedoctorisin17@gmail.com>
 */
Class FileInfo extends \SplFileInfo
{
    private string $relativePath;


    public function __construct(string $file, string $relativePath = '')
    {
        parent::__construct($file);
        $this->setInfoClass(static::class);
        $this->relativePath = $relativePath;
    }


    /**
     * Returns the relative directory path.
     */
    public function getRelativePath(): string
    {
        return $this->relativePath;
    }


    /**
     * Returns the relative path including file name.
     */
    public function getRelativePathname(): string
    {
        return ($this->relativePath === '' ? '' : $this->relativePath . DIRECTORY_SEPARATOR)
            . $this->getBasename();
    }
}