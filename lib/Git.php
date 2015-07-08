<?php
/*
 * Copyright (C) 2008, 2009 Patrik Fimml
 *
 * This file is part of glip.
 *
 * glip is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.

 * glip is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with glip.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace ennosuke\glip;

class Git
{
    public $dir;

    const OBJ_NONE = 0;
    const OBJ_COMMIT = 1;
    const OBJ_TREE = 2;
    const OBJ_BLOB = 3;
    const OBJ_TAG = 4;
    const OBJ_OFS_DELTA = 6;
    const OBJ_REF_DELTA = 7;

    public static function getTypeID($name)
    {
        if ($name == 'commit') {
            return Git::OBJ_COMMIT;
        } elseif ($name == 'tree') {
            return Git::OBJ_TREE;
        } elseif ($name == 'blob') {
            return Git::OBJ_BLOB;
        } elseif ($name == 'tag') {
            return Git::OBJ_TAG;
        }
        throw new Exception(sprintf('unknown type name: %s', $name));
    }

    public static function getTypeName($type)
    {
        if ($type == Git::OBJ_COMMIT) {
            return 'commit';
        } elseif ($type == Git::OBJ_TREE) {
            return 'tree';
        } elseif ($type == Git::OBJ_BLOB) {
            return 'blob';
        } elseif ($type == Git::OBJ_TAG) {
            return 'tag';
        }
        throw new Exception(sprintf('no string representation of type %d', $type));
    }

    public function __construct($dir)
    {
        $this->dir = realpath($dir);
        if ($this->dir === false || !@is_dir($this->dir)) {
            throw new Exception(sprintf('not a directory: %s', $dir));
        }

        $this->packs = array();
        $dirHandler = opendir(sprintf('%s/objects/pack', $this->dir));
        if ($dirHandler !== false) {
            while (($entry = readdir($dirHandler)) !== false) {
                if (preg_match('#^pack-([0-9a-fA-F]{40})\.idx$#', $entry, $matches)) {
                    $this->packs[] = sha1Bin($matches[1]);
                }
            }
            closedir($dirHandler);
        }
        stream_filter_register("Gz.*", 'ennosuke\glip\filter\Gz');
    }

    /**
     * @brief Tries to find $objectName in the fanout table in $f at $offset.
     *
     * @returns array The range where the object can be located (first possible
     * location and past-the-end location)
     */
    protected function readFanout($file, $objectName, $offset)
    {
        if ($objectName{0} == "\x00") {
            $cur = 0;
            fseek($file, $offset);
            $after = Binary::fuint32($file);
        } else {
            fseek($file, $offset + (ord($objectName{0}) - 1)*4);
            $cur = Binary::fuint32($file);
            $after = Binary::fuint32($file);
        }

        return array($cur, $after);
    }

    /**
     * @brief Try to find an object in a pack.
     *
     * @param $objectName (string) name of the object (binary SHA1)
     * @returns (array) an array consisting of the name of the pack (string) and
     * the byte offset inside it, or NULL if not found
     */
    protected function findPackedObject($objectName)
    {
        foreach ($this->packs as $packName) {
            $index = fopen(sprintf('%s/objects/pack/pack-%s.idx', $this->dir, sha1Hex($packName)), 'rb');
            flock($index, LOCK_SH);

            /* check version */
            $magic = fread($index, 4);
            if ($magic != "\xFFtOc") {
            /* version 1 */
                /* read corresponding fanout entry */
                list($cur, $after) = $this->readFanout($index, $objectName, 0);

                $n = $after-$cur;
                if ($n == 0) {
                    continue;
                }

                /*
                 * TODO: do a binary search in [$offset, $offset+24*$n)
                 */
                fseek($index, 4*256 + 24*$cur);
                for ($i = 0; $i < $n; $i++) {
                    $off = Binary::fuint32($index);
                    $name = fread($index, 20);
                    if ($name == $objectName) {
                    /* we found the object */
                        fclose($index);
                        return array($packName, $off);
                    }
                }
            } else {
                /* version 2+ */
                $version = Binary::fuint32($index);
                if ($version == 2) {
                    list($cur, $after) = $this->readFanout($index, $objectName, 8);

                    if ($cur == $after) {
                        continue;
                    }

                    fseek($index, 8 + 4*255);
                    $totalObjects = Binary::fuint32($index);

                    /* look up sha1 */
                    fseek($index, 8 + 4*256 + 20*$cur);
                    for ($i = $cur; $i < $after; $i++) {
                        $name = fread($index, 20);
                        if ($name == $objectName) {
                            break;
                        }
                    }
                    if ($i == $after) {
                        continue;
                    }

                    fseek($index, 8 + 4*256 + 24*$totalObjects + 4*$i);
                    $off = Binary::fuint32($index);
                    if ($off & 0x80000000) {
                    /* packfile > 2 GB. Gee, you really want to handle this
                         * much data with PHP?
                         */
                        throw new Exception('64-bit packfiles offsets not implemented');
                    }

                    fclose($index);
                    return array($packName, $off);
                } else {
                    throw new Exception('unsupported pack index format');
                }
            }
            fclose($index);
        }
        /* not found */
        return null;
    }

    /**
     * @brief Apply the git delta $delta to the byte sequence $base.
     *
     * @param $delta (string) the delta to apply
     * @param $base (string) the sequence to patch
     * @returns (string) the patched byte sequence
     */
    protected function applyDelta($delta, $base)
    {
        $pos = 0;

        $baseSize = Binary::gitVarInt($delta, $pos);
        $resultSize = Binary::gitVarInt($delta, $pos);

        $result = '';
        while ($pos < strlen($delta)) {
            $opcode = ord($delta{$pos++});
            if ($opcode & 0x80) {
            /* copy a part of $base */
                $off = 0;
                if ($opcode & 0x01) {
                    $off = ord($delta{$pos++});
                }
                if ($opcode & 0x02) {
                    $off |= ord($delta{$pos++}) <<  8;
                }
                if ($opcode & 0x04) {
                    $off |= ord($delta{$pos++}) << 16;
                }
                if ($opcode & 0x08) {
                    $off |= ord($delta{$pos++}) << 24;
                }
                $len = 0;
                if ($opcode & 0x10) {
                    $len = ord($delta{$pos++});
                }
                if ($opcode & 0x20) {
                    $len |= ord($delta{$pos++}) <<  8;
                }
                if ($opcode & 0x40) {
                    $len |= ord($delta{$pos++}) << 16;
                }
                if ($len == 0) {
                    $len = 0x10000;
                }
                $result .= substr($base, $off, $len);
            } else {
                /* take the next $opcode bytes as they are */
                $result .= substr($delta, $pos, $opcode);
                $pos += $opcode;
            }
        }
        return $result;
    }

    /**
     * @brief Unpack an object from a pack.
     *
     * @param $pack (resource) open .pack file
     * @param $objectOffset (integer) offset of the object in the pack
     * @returns (array) an array consisting of the object type (int) and the
     * binary representation of the object (string)
     */
    protected function unpackObject($pack, $objectOffset)
    {
        fseek($pack, $objectOffset);

        /* read object header */
        $char = ord(fgetc($pack));
        $type = ($char >> 4) & 0x07;
        $size = $char & 0x0F;
        for ($i = 4; $char & 0x80; $i += 7) {
            $char = ord(fgetc($pack));
            $size |= (($char & 0x7F) << $i);
        }

        /* compare sha1_file.c:1608 unpack_entry */
        if ($type == Git::OBJ_COMMIT || $type == Git::OBJ_TREE || $type == Git::OBJ_BLOB || $type == Git::OBJ_TAG) {
        /*
             * We don't know the actual size of the compressed
             * data, so we'll assume it's less than
             * $objectSize+512.
             *
             */;
            $pack = stream_filter_append($pack, "Gz.uncompress");
            $data = freac($pack, $size+512);
        } elseif ($type == Git::OBJ_OFS_DELTA) {
        /* 20 = maximum varint length for offset */
            $buf = fread($pack, $size+512+20);

            /*
             * contrary to varints in other places, this one is big endian
             * (and 1 is added each turn)
             * see sha1_file.c (get_delta_base)
             */
            $pos = 0;
            $offset = -1;
            do {
                $offset++;
                $char = ord($buf{$pos++});
                $offset = ($offset << 7) + ($char & 0x7F);
            } while ($char & 0x80);

            $delta = gzuncompress(substr($buf, $pos), $size);
            unset($buf);

            $baseOffset = $objectOffset - $offset;
            assert($baseOffset >= 0);
            list($type, $base) = $this->unpackObject($pack, $baseOffset);

            $data = $this->applyDelta($delta, $base);
        } elseif ($type == Git::OBJ_REF_DELTA) {
            $baseName = fread($pack, 20);
            list($type, $base) = $this->getRawObject($baseName);

            // $size is the length of the uncompressed delta
            $delta = gzuncompress(fread($pack, $size+512), $size);

            $data = $this->applyDelta($delta, $base);
        } else {
            throw new Exception(sprintf('object of unknown type %d', $type));
        }

        return array($type, $data);
    }

    /**
     * @brief Fetch an object in its binary representation by name.
     *
     * Throws an exception if the object cannot be found.
     *
     * @param $objectName (string) name of the object (binary SHA1)
     * @returns (array) an array consisting of the object type (int) and the
     * binary representation of the object (string)
     */
    protected function getRawObject($objectName)
    {
        static $cache = array();
        /* FIXME allow limiting the cache to a certain size */

        if (isset($cache[$objectName])) {
            return $cache[$objectName];
        }
        $sha1 = Git::sha1Hex($objectName);
        $path = sprintf('%s/objects/%s/%s', $this->dir, substr($sha1, 0, 2), substr($sha1, 2));
        if (file_exists($path)) {
            list($hdr, $objectData) = explode("\0", gzuncompress(file_get_contents($path)), 2);

            sscanf($hdr, "%s %d", $type, $objectSize);
            $objectType = Git::getTypeID($type);
            $result = array($objectType, $objectData);
        } elseif ($packedObject = $this->findPackedObject($objectName)) {
            list($packName, $objectOffset) = $packedObject;

            $pack = fopen(sprintf('%s/objects/pack/pack-%s.pack', $this->dir, sha1Hex($packName)), 'rb');
            flock($pack, LOCK_SH);

            /* check magic and version */
            $magic = fread($pack, 4);
            $version = Binary::fuint32($pack);
            if ($magic != 'PACK' || $version != 2) {
                throw new Exception('unsupported pack format');
            }

            $result = $this->unpackObject($pack, $objectOffset);
            fclose($pack);
        } else {
            throw new Exception(sprintf('object not found: %s', sha1Hex($objectName)));
        }
        $cache[$objectName] = $result;
        return $result;
    }

    /**
     * @brief Fetch an object in its PHP representation.
     *
     * @param $name (string) name of the object (binary SHA1)
     * @returns (GitObject) the object
     */
    public function getObject($name)
    {
        list($type, $data) = $this->getRawObject($name);
        $object = object\GitObject::create($this, $type);
        $object->unserialize($data);
        assert($name == $object->getName());
        return $object;
    }

    /**
     * @brief Look up a branch.
     *
     * @param $branch (string) The branch to look up, defaulting to @em master.
     * @returns (string) The tip of the branch (binary sha1).
     */
    public function getTip($branch = 'master')
    {
        $subpath = sprintf('refs/heads/%s', $branch);
        $path = sprintf('%s/%s', $this->dir, $subpath);
        if (file_exists($path)) {
            return Git::sha1Bin(file_get_contents($path));
        }
        $path = sprintf('%s/packed-refs', $this->dir);
        if (file_exists($path)) {
            $head = null;
            $file = fopen($path, 'rb');
            flock($file, LOCK_SH);
            while ($head === null && ($line = fgets($file)) !== false) {
                if ($line{0} == '#') {
                    continue;
                }
                $parts = explode(' ', trim($line));
                if (count($parts) == 2 && $parts[1] == $subpath) {
                    $head = sha1Bin($parts[0]);
                }
            }
            fclose($file);
            if ($head !== null) {
                return $head;
            }
        }
        throw new \Exception(sprintf('no such branch: %s', $branch));
    }

    public function getTag($tag)
    {
        $subpath = sprintf('refs/tags/%s', $tag);
        $path = sprintf('%s/%s', $this->dir, $subpath);
        if (file_exists($path)) {
            return Git::sha1Bin(file_get_contents($path));
        }
    }

    public static function sha1Bin($hex)
    {
        return pack('H40', $hex);
    }

    public static function sha1Hex($bin)
    {
        return bin2hex($bin);
    }
}
