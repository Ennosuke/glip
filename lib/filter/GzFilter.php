<?php

namespace ennosuke\glip\filter;

class GzFilter extends \php_user_filter
{
    private $mode;

    public function onCreate()
    {
        if ($this->filtername == "Gz.compress") {
            $this->mode = 0;
        } elseif ($this->filtername == "Gz.uncompress") {
            $this->mode = 1;
        } else {
            return false;
        }
        return true;
    }

    public function filter($input, $output, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($input)) {
            if ($this->mode == 0) {
                $bucket->data = gzcompress($bucket->data);
            } elseif ($this->mode == 1) {
                $bucket->data = gzuncompress($bucket->data);
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($output, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
