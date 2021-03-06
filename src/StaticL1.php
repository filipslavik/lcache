<?php

namespace LCache;

class StaticL1 extends L1
{
    protected $hits;
    protected $misses;
    protected $key_overhead;
    protected $storage;
    protected $last_applied_event_id;

    public function __construct($pool = null)
    {
        parent::__construct($pool);

        $this->hits = 0;
        $this->misses = 0;
        $this->key_overhead = [];
        $this->storage = array();
        $this->last_applied_event_id = null;
    }

    public function getKeyOverhead(Address $address)
    {
        $local_key = $address->serialize();
        if (array_key_exists($local_key, $this->key_overhead)) {
            return $this->key_overhead[$local_key];
        }
        return 0;
    }

    public function setWithExpiration($event_id, Address $address, $value, $created, $expiration = null)
    {
        $local_key = $address->serialize();

        // If not setting a negative cache entry, increment the key's overhead.
        if (!is_null($value)) {
            if (isset($this->key_overhead[$local_key])) {
                $this->key_overhead[$local_key]++;
            } else {
                $this->key_overhead[$local_key] = 1;
            }
        }

        // Don't overwrite local entries that are even newer or the same age.
        if (isset($this->storage[$local_key]) && $this->storage[$local_key]->event_id >= $event_id) {
            return true;
        }
        $this->storage[$local_key] = new Entry($event_id, $this->getPool(), $address, $value, $created, $expiration);

        return true;
    }

    public function isNegativeCache(Address $address)
    {
        $local_key = $address->serialize();
        return (isset($this->storage[$local_key]) && is_null($this->storage[$local_key]->value));
    }

    public function getEntry(Address $address)
    {
        $local_key = $address->serialize();

        // Decrement the key's overhead.
        if (isset($this->key_overhead[$local_key])) {
            $this->key_overhead[$local_key]--;
        } else {
            $this->key_overhead[$local_key] = -1;
        }

        if (!array_key_exists($local_key, $this->storage)) {
            $this->misses++;
            return null;
        }
        $entry = $this->storage[$local_key];
        if (!is_null($entry->expiration) && $entry->expiration < $_SERVER['REQUEST_TIME']) {
            unset($this->storage[$local_key]);
            $this->misses++;
            return null;
        }

        $this->hits++;
        return $entry;
    }

    public function delete($event_id, Address $address)
    {
        $local_key = $address->serialize();
        if ($address->isEntireCache()) {
            $this->storage = array();
            return true;
        } elseif ($address->isEntireBin()) {
            foreach ($this->storage as $index => $value) {
                if (strpos($index, $local_key) === 0) {
                    unset($this->storage[$index]);
                }
            }
            return true;
        }
        $this->setLastAppliedEventID($event_id);
        // @TODO: Consider adding "race" protection here, like for set.
        unset($this->storage[$local_key]);
        return true;
    }

    public function getHits()
    {
        return $this->hits;
    }

    public function getMisses()
    {
        return $this->misses;
    }

    public function getLastAppliedEventID()
    {
        return $this->last_applied_event_id;
    }

    public function setLastAppliedEventID($eid)
    {
        $this->last_applied_event_id = $eid;
        return true;
    }
}
