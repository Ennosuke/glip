<?php
/*
 * binary.class.php:
 * Utility functions for dealing with binary files/strings.
 * All functions assume network byte order (big-endian).
 *
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

final class Binary
{
    public static function uint16($str, $pos = 0)
    {
        return ord($str{$pos+0}) << 8 | ord($str{$pos+1});
    }

    public static function uint32($str, $pos = 0)
    {
        $result = unpack('Nx', substr($str, $pos, 4));
        return $result['x'];
    }

    public static function nuint32($number, $str, $pos = 0)
    {
        $reuslt = array();
        for ($i = 0; $i < $number; $i++, $pos += 4) {
            $reuslt[] = Binary::uint32($str, $pos);
        }
        return $reuslt;
    }

    public static function fuint32($file)
    {
        return Binary::uint32(fread($file, 4));
    }
    public static function nfuint32($number, $file)
    {
        return Binary::nuint32($number, fread($file, 4*$number));
    }

    public static function gitVarInt($str, &$pos = 0)
    {
        $result = 0;
        $char = 0x80;
        for ($i = 0; $char & 0x80; $i += 7) {
            $char = ord($str{$pos++});
            $result |= (($char & 0x7F) << $i);
        }
        return $result;
    }
}
