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


#require_once('git_object.class.php');

namespace ennosuke\glip\object;

use ennosuke\glip\Git as Git;
use \StdClass as StdClass;

class GitTree extends GitObject
{
    public $nodes = array();

    public function __construct($repo)
    {
        parent::__construct($repo, Git::OBJ_TREE);
    }

    public function unserialize($data)
    {
        parent::unserialize($data);
        $this->nodes = array();
        $start = 0;
        while ($start < strlen($data)) {
            $node = new stdClass;

            $pos = strpos($data, "\0", $start);
            list($node->mode, $node->name) = explode(' ', substr($data, $start, $pos-$start), 2);
            $node->mode = intval($node->mode, 8);
            $node->is_dir = !!($node->mode & 040000);
            $node->is_submodule = ($node->mode == 57344);
            $node->object = substr($data, $pos+1, 20);
            $start = $pos+21;

            $this->nodes[$node->name] = $node;
        }
        unset($data);
    }

    protected static function nodecmp(&$nodeA, &$nodeB)
    {
        return strcmp($nodeA ->name, $nodeB->name);
    }

    public function serialize()
    {
        $serialized = '';
        /* git requires nodes to be sorted */
        uasort($this->nodes, array('GitTree', 'nodecmp'));
        foreach ($this->nodes as $node) {
            $serialized .= sprintf("%s %s\0%s", base_convert($node->mode, 10, 8), $node->name, $node->object);
        }
        return $serialized;
    }

    /**
     * @brief Find the tree or blob at a certain path.
     *
     * @throws GitTreeInvalidPathError The path was found to be invalid. This
     * can happen if you are trying to treat a file like a directory (i.e.
     * @em foo/bar where @em foo is a file).
     *
     * @param $path (string) The path to look for, relative to this tree.
     * @returns The GitTree or GitBlob at the specified path, or NULL if none
     * could be found.
     */
    public function find($path)
    {
        if (!is_array($path)) {
            $path = explode('/', $path);
        }

        while ($path && !$path[0]) {
            array_shift($path);
        }
        if (!$path) {
            return $this->getName();
        }

        if (!isset($this->nodes[$path[0]])) {
            return null;
        }
        $cur = $this->nodes[$path[0]]->object;

        array_shift($path);
        while ($path && !$path[0]) {
            array_shift($path);
        }

        if (!$path) {
            return $cur;
        } else {
            $cur = $this->repo->getObject($cur);
            if (!($cur instanceof GitTree)) {
                throw new GitTreeInvalidPathError;
            }
            return $cur->find($path);
        }
    }

    /**
     * @brief Recursively list the contents of a tree.
     *
     * @returns (array mapping string to string) An array where the keys are
     * paths relative to the current tree, and the values are SHA-1 names of
     * the corresponding blobs in binary representation.
     */
    public function listRecursive()
    {
        $content = array();

        foreach ($this->nodes as $node) {
            if ($node->is_dir) {
                if ($node->is_submodule) {
                    $content[$node->name. ':submodule'] = $node->object;
                } else {
                    $subtree = $this->repo->getObject($node->object);
                    foreach ($subtree->listRecursive() as $entry => $blob) {
                        $content[$node->name . '/' . $entry] = $blob;
                    }
                }
            } else {
                $content[$node->name] = $node->object;
            }
        }

        return $content;
    }

    /**
     * @brief Updates a node in this tree.
     *
     * Missing directories in the path will be created automatically.
     *
     * @param $path (string) Path to the node, relative to this tree.
     * @param $mode Git mode to set the node to. 0 if the node shall be
     * cleared, i.e. the tree or blob shall be removed from this path.
     * @param $object (string) Binary SHA-1 hash of the object that shall be
     * placed at the given path.
     *
     * @returns (array of GitObject) An array of GitObject%s that were newly
     * created while updating the specified node. Those need to be written to
     * the repository together with the modified tree.
     */
    public function updateNode($path, $mode, $object)
    {
        if (!is_array($path)) {
            $path = explode('/', $path);
        }
        $name = array_shift($path);
        if (count($path) == 0) {
        /* create leaf node */
            if ($mode) {
                $node = new stdClass;
                $node->mode = $mode;
                $node->name = $name;
                $node->object = $object;
                $node->is_dir = !!($mode & 040000);

                $this->nodes[$node->name] = $node;
            } else {
                unset($this->nodes[$name]);
            }

            return array();
        } else {
            /* descend one level */
            if (isset($this->nodes[$name])) {
                $node = $this->nodes[$name];
                if (!$node->is_dir) {
                    throw new GitTreeInvalidPathError;
                }
                $subtree = clone $this->repo->getObject($node->object);
            } else {
                /* create new tree */
                $subtree = new GitTree($this->repo);

                $node = new stdClass;
                $node->mode = 040000;
                $node->name = $name;
                $node->is_dir = true;

                $this->nodes[$node->name] = $node;
            }
            $pending = $subtree->updateNode($path, $mode, $object);

            $subtree->rehash();
            $node->object = $subtree->getName();

            $pending[] = $subtree;
            return $pending;
        }
    }

    const TREEDIFF_A = 0x01;
    const TREEDIFF_B = 0x02;

    const TREEDIFF_REMOVED = self::TREEDIFF_A;
    const TREEDIFF_ADDED = self::TREEDIFF_B;
    const TREEDIFF_CHANGED = 0x03;

    public static function treeDiff($aTree, $bTree)
    {
        $aBlobs = $aTree ? $aTree->listRecursive() : array();
        $bBlobs = $bTree ? $bTree->listRecursive() : array();

        $aFiles = array_keys($aBlobs);
        $bFiles = array_keys($bBlobs);

        $changes = array();

        sort($aFiles);
        sort($bFiles);
        $aIndex = $bIndex = 0;
        while ($aIndex < count($aFiles) || $bIndex < count($bFiles)) {
            if ($aIndex < count($aFiles) && $bIndex < count($bFiles)) {
                $cmp = strcmp($aFiles[$aIndex], $bFiles[$bIndex]);
            } else {
                $cmp = 0;
            }
            if ($bIndex >= count($bFiles) || $cmp < 0) {
                $changes[$aFiles[$aIndex]] = self::TREEDIFF_REMOVED;
                $aIndex++;
            } elseif ($aIndex >= count($aFiles) || $cmp > 0) {
                $changes[$bFiles[$bIndex]] = self::TREEDIFF_ADDED;
                $bIndex++;
            } else {
                if ($aBlobs[$aFiles[$aIndex]] != $bBlobs[$bFiles[$bIndex]]) {
                    $changes[$aFiles[$aIndex]] = self::TREEDIFF_CHANGED;
                }

                $aIndex++;
                $bIndex++;
            }
        }

        return $changes;
    }
}
