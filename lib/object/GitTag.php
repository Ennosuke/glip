<?php

namespace ennosuke\glip\object;

use ennosuke\glip\Git as Git;

class GitTag extends GitObject
{
    public $object;

    public $type;

    public $tag;

    public $tagger;

    public $message;

    public function __construct($repo)
    {
        parent::__construct($repo, Git::OBJ_TAG);
    }

    public function unserialize($data)
    {
        parent::unserialize($data);
        $lines = explode("\n", $data);
        unset($data);
        $meta = [];
        while (($line = array_shift($lines)) != '') {
            $parts = explode(' ', $line, 2);
            if (!isset($meta[$parts[0]])) {
                $meta[$parts[0]] = [$parts[1]];
            } else {
                $meta[$parts[0]][] = $parts[1];
            }
        }

        $this->object = Git::sha1Bin($meta['object'][0]);
        $this->type = $meta['type'][0];
        $this->tag = $meta['tag'][0];
        $this->tagger = new GitStamp;
        $this->tagger->unserialize($meta['tagger'][0]);
        $this->message = implode("\n", $lines);
    }

    public function serialize()
    {
        $serialized = '';
        $serialized = sprintf("object %s\n", Git::sha1Hex($this->object));
        $serialized = sprintf("type %s\n", $this->type);
        $serialized = sprintf("tag %s\n", $this->tag);
        $serialized = sprintf("tagger %s\n", $this->tagger->serialize());
        $serialized = sprintf("\n%s\n", $this->message);
        return $serialized;
    }
}
