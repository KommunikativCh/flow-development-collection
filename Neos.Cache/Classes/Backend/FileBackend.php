<?php
declare(strict_types=1);

namespace Neos\Cache\Backend;

/*
 * This file is part of the Neos.Cache package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Cache\Exception;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;
use Neos\Utility\OpcodeCacheHelper;

/**
 * A caching backend which stores cache entries in files
 *
 * @api
 */
class FileBackend extends SimpleFileBackend implements PhpCapableBackendInterface, FreezableBackendInterface, TaggableBackendInterface
{
    const SEPARATOR = '^';

    const EXPIRYTIME_FORMAT = 'YmdHis';

    /**
     * @deprecated will be removed in Neos 9.0
     */
    const EXPIRYTIME_LENGTH = 14;

    /**
     * @deprecated will be removed in Neos 9.0
     */
    const DATASIZE_DIGITS = 10;

    /**
     * A file extension to use for each cache entry.
     *
     * @var string
     */
    protected $cacheEntryFileExtension = '';

    /**
     * @var array<string>
     */
    protected $cacheEntryIdentifiers = [];

    /**
     * @var boolean
     */
    protected $frozen = false;

    /**
     * Freezes this cache backend.
     *
     * All data in a frozen backend remains unchanged and methods which try to add
     * or modify data result in an exception thrown. Possible expiry times of
     * individual cache entries are ignored.
     *
     * On the positive side, a frozen cache backend is much faster on read access.
     * A frozen backend can only be thawed by calling the flush() method.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function freeze(): void
    {
        if ($this->frozen === true) {
            throw new \RuntimeException(sprintf('The cache "%s" is already frozen.', $this->cacheIdentifier), 1323353176);
        }

        for ($directoryIterator = new \DirectoryIterator($this->cacheDirectory); $directoryIterator->valid(); $directoryIterator->next()) {
            if ($directoryIterator->isDot()) {
                continue;
            }
            $entryIdentifier = $this->getEntryIdentifierFromFilename($directoryIterator->getFilename());
            $this->cacheEntryIdentifiers[$entryIdentifier] = true;

            $cacheEntryPathAndFilename = $this->cacheDirectory . $entryIdentifier . $this->cacheEntryFileExtension;
            $this->writeCacheFile($cacheEntryPathAndFilename, (string)$this->internalGet($entryIdentifier, false));
        }

        $cachePathAndFileName = $this->cacheDirectory . 'FrozenCache.data';
        if ($this->useIgBinary === true) {
            $data = igbinary_serialize($this->cacheEntryIdentifiers);
        } else {
            $data = serialize($this->cacheEntryIdentifiers);
        }
        if ($this->writeCacheFile($cachePathAndFileName, $data) !== false) {
            $this->frozen = true;
        }
    }

    /**
     * Tells if this backend is frozen.
     *
     * @return boolean
     */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Sets a reference to the cache frontend which uses this backend and
     * initializes the default cache directory.
     *
     * This method also detects if this backend is frozen and sets the internal
     * flag accordingly.
     *
     * @param FrontendInterface $cache The cache frontend
     * @return void
     * @throws Exception
     */
    public function setCache(FrontendInterface $cache): void
    {
        parent::setCache($cache);

        if (is_file($this->cacheDirectory . 'FrozenCache.data')) {
            $this->frozen = true;
            $cachePathAndFileName = $this->cacheDirectory . 'FrozenCache.data';
            $data = $this->readCacheFile($cachePathAndFileName);
            if ($this->useIgBinary === true) {
                $this->cacheEntryIdentifiers = igbinary_unserialize((string)$data);
            } else {
                $this->cacheEntryIdentifiers = unserialize((string)$data);
            }
        }
    }

    /**
     * Saves data in a cache file.
     *
     * @param string $entryIdentifier An identifier for this specific cache entry
     * @param string $data The data to be stored
     * @param array $tags Tags to associate with this cache entry
     * @param integer $lifetime Lifetime of this cache entry in seconds. If NULL is specified, the default lifetime is used. "0" means unlimited lifetime.
     * @return void
     * @throws \RuntimeException
     * @throws Exception if the directory does not exist or is not writable or exceeds the maximum allowed path length, or if no cache frontend has been set.
     * @throws \InvalidArgumentException
     * @api
     */
    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
        if ($entryIdentifier !== basename($entryIdentifier)) {
            throw new \InvalidArgumentException('The specified entry identifier must not contain a path segment.', 1282073032);
        }
        if ($entryIdentifier === '') {
            throw new \InvalidArgumentException('The specified entry identifier must not be empty.', 1298114280);
        }
        if ($this->frozen === true) {
            throw new \RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1323344192);
        }

        $cacheEntryPathAndFilename = $this->cacheDirectory . $entryIdentifier . $this->cacheEntryFileExtension;
        $lifetime = $lifetime ?? $this->defaultLifetime;
        $expiryTime = ($lifetime === 0) ? 0 : (time() + $lifetime);
        $entry = new FileBackendEntryDto($data, $tags, $expiryTime);
        $result = $this->writeCacheFile($cacheEntryPathAndFilename, (string)$entry);
        if ($result !== false) {
            if ($this->cacheEntryFileExtension === '.php') {
                OpcodeCacheHelper::clearAllActive($cacheEntryPathAndFilename);
            }
            return;
        }

        $this->throwExceptionIfPathExceedsMaximumLength($cacheEntryPathAndFilename);
        throw new Exception('The cache file "' . $cacheEntryPathAndFilename . '" could not be written.', 1222361632);
    }

    /**
     * Loads data from a cache file.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     * @return mixed The cache entry's content as a string or false if the cache entry could not be loaded
     * @throws \InvalidArgumentException
     * @api
     */
    public function get(string $entryIdentifier)
    {
        return $this->frozen ? $this->internalGetWhileFrozen($entryIdentifier) : $this->internalGet($entryIdentifier);
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier
     * @return boolean true if such an entry exists, false if not
     * @throws \InvalidArgumentException
     * @api
     */
    public function has(string $entryIdentifier): bool
    {
        if ($this->frozen === true) {
            return isset($this->cacheEntryIdentifiers[$entryIdentifier]);
        }
        if ($entryIdentifier !== basename($entryIdentifier)) {
            throw new \InvalidArgumentException('The specified entry identifier must not contain a path segment.', 1282073034);
        }
        return !$this->isCacheFileExpired($this->cacheDirectory . $entryIdentifier . $this->cacheEntryFileExtension);
    }

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     * @return boolean true if (at least) an entry could be removed or false if no entry was found
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @api
     */
    public function remove(string $entryIdentifier): bool
    {
        if ($this->frozen === true) {
            throw new \RuntimeException(sprintf('Cannot remove cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1323344193);
        }

        return parent::remove($entryIdentifier);
    }

    /**
     * Finds and returns all cache entry identifiers which are tagged by the
     * specified tag.
     *
     * @param string $searchedTag The tag to search for
     * @return string[] An array with identifiers of all matching entries. An empty array if no entries matched
     * @api
     */
    public function findIdentifiersByTag(string $searchedTag): array
    {
        return $this->findIdentifiersByTags([$searchedTag]);
    }

    /**
     * Finds and returns all cache entry identifiers which are tagged by the
     * specified tags.
     *
     * @param string[] $tags The tags to search for
     * @return string[] An array with identifiers of all matching entries. An empty array if no entries matched or no tags were provided
     * @api
     */
    public function findIdentifiersByTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $entryIdentifiers = [];
        for ($directoryIterator = new \DirectoryIterator($this->cacheDirectory); $directoryIterator->valid(); $directoryIterator->next()) {
            if ($directoryIterator->isDot()) {
                continue;
            }

            $cacheEntryPathAndFilename = $directoryIterator->getPathname();
            $allData = $this->readCacheFile($cacheEntryPathAndFilename);
            if ($allData === false) {
                continue;
            }
            $entry = FileBackendEntryDto::fromString($allData);
            if ($entry->isExpired()) {
                continue;
            }

            $extractedTags = $entry->getTags();
            if ($extractedTags === [] || !array_intersect($tags, $extractedTags)) {
                continue;
            }

            $entryIdentifiers[] = $this->getEntryIdentifierFromFilename($directoryIterator->getFilename());
        }
        return $entryIdentifiers;
    }

    /**
     * Removes all cache entries of this cache and sets the frozen flag to false.
     *
     * @return void
     * @throws FilesException
     * @api
     */
    public function flush(): void
    {
        Files::emptyDirectoryRecursively($this->cacheDirectory);
        if ($this->frozen === true) {
            try {
                @unlink($this->cacheDirectory . 'FrozenCache.data');
            } catch (\Throwable $e) {
                // PHP 8 apparently throws for unlink even with shutup operator, but we really don't care at this place. It's also the only way to handle this race-condition free.
            }
            $this->frozen = false;
        }
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     * @return integer The number of entries which have been affected by this flush
     * @api
     */
    public function flushByTag(string $tag): int
    {
        return $this->flushByTags([$tag]);
    }

    /**
     * Removes all cache entries of this cache which are tagged by any of the specified tags.
     *
     * @api
     */
    public function flushByTags(array $tags): int
    {
        $identifiers = $this->findIdentifiersByTags($tags);

        foreach ($identifiers as $entryIdentifier) {
            $this->remove($entryIdentifier);
        }

        return count($identifiers);
    }

    /**
     * Checks if the given cache entry files are still valid or if their
     * lifetime has exceeded.
     *
     * @param string $cacheEntryPathAndFilename
     * @param boolean $acquireLock
     * @return boolean
     * @api
     */
    protected function isCacheFileExpired(string $cacheEntryPathAndFilename, bool $acquireLock = true): bool
    {
        if (is_file($cacheEntryPathAndFilename) === false) {
            return true;
        }

        $allData = $this->readCacheFile($cacheEntryPathAndFilename);
        if ($allData === false) {
            return true;
        }
        return FileBackendEntryDto::fromString($allData)->isExpired();
    }

    /**
     * Does garbage collection
     *
     * @return void
     * @api
     */
    public function collectGarbage(): void
    {
        if ($this->frozen === true) {
            return;
        }

        for ($directoryIterator = new \DirectoryIterator($this->cacheDirectory); $directoryIterator->valid(); $directoryIterator->next()) {
            if ($directoryIterator->isDot()) {
                continue;
            }

            if ($this->isCacheFileExpired($directoryIterator->getPathname())) {
                $this->remove($directoryIterator->getBasename($this->cacheEntryFileExtension));
            }
        }
    }

    /**
     * Tries to find the cache entry for the specified identifier.
     * Usually only one cache entry should be found - if more than one exist, this
     * is due to some error or crash.
     *
     * @param string $entryIdentifier The cache entry identifier
     * @return mixed The filenames (including path) as an array if one or more entries could be found, otherwise false
     */
    protected function findCacheFilesByIdentifier(string $entryIdentifier)
    {
        $pattern = $this->cacheDirectory . $entryIdentifier;
        $filesFound = glob($pattern);
        if ($filesFound === false || count($filesFound) === 0) {
            return false;
        }
        return $filesFound;
    }

    /**
     * Loads PHP code from the cache and require_onces it right away.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     * @return mixed Potential return value from the include operation
     * @throws \InvalidArgumentException
     * @api
     */
    public function requireOnce(string $entryIdentifier)
    {
        $pathAndFilename = $this->cacheDirectory . $entryIdentifier . $this->cacheEntryFileExtension;

        if ($this->frozen === true) {
            if (isset($this->cacheEntryIdentifiers[$entryIdentifier])) {
                return require_once($pathAndFilename);
            }
            return false;
        }


        if ($entryIdentifier !== basename($entryIdentifier)) {
            throw new \InvalidArgumentException('The specified entry identifier (' . $entryIdentifier . ') must not contain a path segment.', 1282073036);
        }
        return ($this->isCacheFileExpired($pathAndFilename)) ? false : require_once($pathAndFilename);
    }

    /**
     * Internal get method, allows to nest locks by using the $acquireLock flag
     *
     * @param string $entryIdentifier
     * @param boolean $acquireLock
     * @return bool|string
     * @throws \InvalidArgumentException
     */
    protected function internalGet(string $entryIdentifier, bool $acquireLock = true)
    {
        if ($entryIdentifier !== basename($entryIdentifier)) {
            throw new \InvalidArgumentException('The specified entry identifier must not contain a path segment.', 1282073033);
        }

        $pathAndFilename = $this->cacheDirectory . $entryIdentifier . $this->cacheEntryFileExtension;

        if ($acquireLock) {
            $cacheData = $this->readCacheFile($pathAndFilename);
        } else {
            $cacheData = file_get_contents($pathAndFilename);
        }

        if ($cacheData === false) {
            return false;
        }

        $entry = FileBackendEntryDto::fromString($cacheData);
        if ($entry->isExpired()) {
            return false;
        }

        return $entry->getData();
    }

    /**
     * Internal get method in case the cache is frozen, this will not check expiry times!
     *
     * @param string $entryIdentifier
     * @return bool|string
     * @throws \InvalidArgumentException
     */
    protected function internalGetWhileFrozen(string $entryIdentifier)
    {
        if ($entryIdentifier !== basename($entryIdentifier)) {
            throw new \InvalidArgumentException('The specified entry identifier must not contain a path segment.', 1667977180);
        }

        $pathAndFilename = $this->cacheDirectory . $entryIdentifier . $this->cacheEntryFileExtension;
        if (!isset($this->cacheEntryIdentifiers[$entryIdentifier])) {
            return false;
        }

        return $this->readCacheFile($pathAndFilename);
    }

    protected function getEntryIdentifierFromFilename(string $filename): string
    {
        $cacheEntryFileExtensionLength = strlen($this->cacheEntryFileExtension);

        return $cacheEntryFileExtensionLength === 0 ? $filename : substr($filename, 0, - strlen($this->cacheEntryFileExtension));
    }
}
