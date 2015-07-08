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

namespace ennosuke\glip\object;

use ennosuke\glip\Git as Git;

class GitCommit extends GitObject
{
    /**
     * @brief (string) The tree referenced by this commit, as binary sha1
     * string.
     */
    public $tree;

    /**
     * @brief (array of string) Parent commits of this commit, as binary sha1
     * strings.
     */
    public $parents;

    /**
     * @brief (GitCommitStamp) The author of this commit.
     */
    public $author;

    /**
     * @brief (GitCommitStamp) The committer of this commit.
     */
    public $committer;

    /**
     * @brief (string) Commit summary, i.e. the first line of the commit message.
     */
    public $summary;

    /**
     * @brief (string) Everything after the first line of the commit message.
     */
    public $detail;

    public function __construct($repo)
    {
        parent::__construct($repo, Git::OBJ_COMMIT);
    }

    public function unserialize($data)
    {
        parent::unserialize($data);
        $lines = explode("\n", $data);
        unset($data);
        $meta = array('parent' => array());
        while (($line = array_shift($lines)) != '') {
            $parts = explode(' ', $line, 2);
            if (!isset($meta[$parts[0]])) {
                $meta[$parts[0]] = array($parts[1]);
            } else {
                $meta[$parts[0]][] = $parts[1];
            }
        }

        $this->tree = Git::sha1Bin($meta['tree'][0]);
        $this->parents = array_map(array("ennosuke\glip\Git", "sha1Bin"), $meta['parent']);
        $this->author = new GitStamp;
        $this->author->unserialize($meta['author'][0]);
        $this->committer = new GitStamp;
        $this->committer->unserialize($meta['committer'][0]);

        $this->summary = array_shift($lines);
        $this->detail = implode("\n", $lines);

        $this->history = null;
    }

    public function serialize()
    {
        $serialized = '';
        $serialized .= sprintf("tree %s\n", Git::sha1Hex($this->tree));
        foreach ($this->parents as $parent) {
            $serialized .= sprintf("parent %s\n", Git::sha1Hex($parent));
        }
        $serialized .= sprintf("author %s\n", $this->author->serialize());
        $serialized .= sprintf("committer %s\n", $this->committer->serialize());
        $serialized .= "\n".$this->summary."\n".$this->detail;
        return $serialized;
    }

    /**
     * @brief Get commit history in topological order.
     *
     * @returns (array of GitCommit)
     */
    public function getHistory()
    {
        if ($this->history) {
            return $this->history;
        }

        /* count incoming edges */
        $inc = array();

        $queue = array($this);
        while (($commit = array_shift($queue)) !== null) {
            foreach ($commit->parents as $parent) {
                if (!isset($inc[$parent])) {
                    $inc[$parent] = 1;
                    $queue[] = $this->repo->getObject($parent);
                } else {
                    $inc[$parent]++;
                }
            }
        }

        $queue = array($this);
        $result = array();
        while (($commit = array_pop($queue)) !== null) {
            array_unshift($result, $commit);
            foreach ($commit->parents as $parent) {
                if (--$inc[$parent] == 0) {
                    $queue[] = $this->repo->getObject($parent);
                }
            }
        }

        $this->history = $result;
        return $result;
    }

    /**
     * @brief Get the tree referenced by this commit.
     *
     * @returns The GitTree referenced by this commit.
     */
    public function getTree()
    {
        return $this->repo->getObject($this->tree);
    }

    /**
     * @copybrief GitTree::find()
     *
     * This is a convenience function calling GitTree::find() on the commit's
     * tree.
     *
     * @copydetails GitTree::find()
     */
    public function find($path)
    {
        return $this->getTree()->find($path);
    }

    public static function treeDiff($commitA, $commitB)
    {
        return GitTree::treeDiff($commitA ? $commitA->getTree() : null, $commitB ? $commitB->getTree() : null);
    }
}
