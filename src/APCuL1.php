<?php

namespace LCache;

class APCuL1 extends L1
{
    /** @var string */
    private $localKeyPrefix;

    /** @var string */
    private $statusKeyHits;

    /** @var string */
    private $statusKeyMisses;

    /** @var string */
    private $statusKeyLastAppliedEventId;

    public function __construct($pool = null)
    {
        parent::__construct($pool);

        // Using designated variables to speed up key generation during runtime.
        $this->localKeyPrefix = 'lcache' . $this->pool . ':';
        $this->statusKeyHits = 'lcache_status:' . $this->pool . ':hits';
        $this->statusKeyMisses = 'lcache_status:' . $this->pool . ':misses';
        $this->statusKeyLastAppliedEventId = 'lcache_status:' . $this->pool . ':last_applied_event_id';
    }

    protected function getLocalKey($address)
    {
        return $this->localKeyPrefix . $address->serialize();
    }

    public function getKeyOverhead(Address $address)
    {
        // @TODO: Consider subtracting APCu's native hits tracker but
        // decrementing the overhead by existing hits when an item is set. This
        // would make hits cheaper but writes more expensive.

        $apcu_key = $this->getLocalKey($address);
        $overhead = apcu_fetch($apcu_key . ':overhead', $success);
        if ($success) {
            return $overhead;
        }
        return 0;
    }

    public function setWithExpiration($event_id, Address $address, $value, $created, $expiration = null)
    {
        $apcu_key = $this->getLocalKey($address);

        // Don't overwrite local entries that are even newer or the same age.
        $entry = apcu_fetch($apcu_key);
        if ($entry !== false && $entry->event_id >= $event_id) {
            return true;
        }
        $entry = new Entry($event_id, $this->pool, $address, $value, $_SERVER['REQUEST_TIME'], $expiration);

        if ($entry->getTTL() === 0) {
            // Item has already expired, but APCu treats a TTL of zero as no TTL.
            // So, we'll set nothing.
            return null;
        }

        $success = apcu_store($apcu_key, $entry, $entry->getTTL());

        // If not setting a negative cache entry, increment the key's overhead.
        if (!is_null($value)) {
            $apcu_key_overhead = $apcu_key . ':overhead';
            apcu_inc($apcu_key_overhead, 1, $overhead_success);
            if (!$overhead_success) {
                // @codeCoverageIgnoreStart
                apcu_store($apcu_key_overhead, 1);
                // @codeCoverageIgnoreEnd
            }
        }

        return $success;
    }

    public function isNegativeCache(Address $address)
    {
        $apcu_key = $this->getLocalKey($address);
        $entry = apcu_fetch($apcu_key, $success);
        return ($success && is_null($entry->value));
    }

    public function getEntry(Address $address)
    {
        $apcu_key = $this->getLocalKey($address);
        $apcu_key_overhead = $apcu_key . ':overhead';

        // Decrement the key's overhead.
        apcu_dec($apcu_key_overhead, 1, $overhead_success);
        if (!$overhead_success) {
            // @codeCoverageIgnoreStart
            apcu_store($apcu_key_overhead, -1);
            // @codeCoverageIgnoreEnd
        }

        $entry = apcu_fetch($apcu_key, $success);
        // Handle failed reads.
        if (!$success) {
            $this->recordMiss();
            return null;
        }

        $this->recordHit();
        return $entry;
    }

    // @TODO: Remove APCIterator support once we only support PHP 7+
    protected function getIterator($pattern, $format = APC_ITER_ALL)
    {
        if (class_exists('APCIterator')) {
            // @codeCoverageIgnoreStart
            return new \APCIterator('user', $pattern, $format);
            // @codeCoverageIgnoreEnd
        }
        // @codeCoverageIgnoreStart
        return new \APCUIterator($pattern, $format);
        // @codeCoverageIgnoreEnd
    }

    public function delete($event_id, Address $address)
    {
        if ($address->isEntireCache()) {
            // @TODO: Consider flushing only LCache L1 storage by using an iterator.
            return apcu_clear_cache();
        }
        if ($address->isEntireBin()) {
            $prefix = $this->getLocalKey($address);
            $pattern = '/^' . preg_quote($prefix) . '.*/';
            $matching = $this->getIterator($pattern, APC_ITER_KEY);
            assert(!is_null($matching), 'Iterator instantiation failed.');
            foreach ($matching as $match) {
                // Ignore failures of delete because the key may have been
                // deleted in another process using the same APCu.
                apcu_delete($match['key']);
            }
            return true;
        }

        $apcu_key = $this->getLocalKey($address);

        // Ignore failures of delete because the key may have been
        // deleted in another process using the same APCu.
        apcu_delete($apcu_key);
        return true;
    }

    protected function recordHit()
    {
        apcu_inc($this->statusKeyHits, 1, $success);
        if (!$success) {
            // @TODO: Remove this fallback when we drop APCu 4.x support.
            // @codeCoverageIgnoreStart
            // Ignore coverage because (1) it's tested with other code and
            // (2) APCu 5.x does not use it.
            apcu_store($this->statusKeyHits, 1);
            // @codeCoverageIgnoreEnd
        }
    }

    protected function recordMiss()
    {
        apcu_inc($this->statusKeyMisses, 1, $success);
        if (!$success) {
            // @TODO: Remove this fallback when we drop APCu 4.x support.
            // @codeCoverageIgnoreStart
            // Ignore coverage because (1) it's tested with other code and
            // (2) APCu 5.x does not use it.
            apcu_store($this->statusKeyMisses, 1);
            // @codeCoverageIgnoreEnd
        }
    }

    public function getHits()
    {
        $value = apcu_fetch($this->statusKeyHits);
        return $value ? $value : 0;
    }

    public function getMisses()
    {
        $value = apcu_fetch($this->statusKeyMisses);
        return $value ? $value : 0;
    }

    public function getLastAppliedEventID()
    {
        $value = apcu_fetch($this->statusKeyLastAppliedEventId);
        if ($value === false) {
            $value = null;
        }
        return $value;
    }

    public function setLastAppliedEventID($eid)
    {
        return apcu_store($this->statusKeyLastAppliedEventId, $eid);
    }
}
