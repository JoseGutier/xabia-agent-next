<?php

namespace Google\Protobuf\Internal;

use Google\Protobuf\Internal\Uint64;

class CodedInputStream
{

    private $buffer;
    private $buffer_size_after_limit;
    private $buffer_end;
    private $current;
    private $current_limit;
    private $legitimate_message_end;
    private $recursion_budget;
    private $recursion_limit;
    private $total_bytes_limit;
    private $total_bytes_read;

    const MAX_VARINT_BYTES = 10;
    const DEFAULT_RECURSION_LIMIT = 100;
    const DEFAULT_TOTAL_BYTES_LIMIT = 33554432; 

    public function __construct($buffer)
    {
        $start = 0;
        $end = strlen($buffer);
        $this->buffer = $buffer;
        $this->buffer_size_after_limit = 0;
        $this->buffer_end = $end;
        $this->current = $start;
        $this->current_limit = $end;
        $this->legitimate_message_end = false;
        $this->recursion_budget = self::DEFAULT_RECURSION_LIMIT;
        $this->recursion_limit = self::DEFAULT_RECURSION_LIMIT;
        $this->total_bytes_limit = self::DEFAULT_TOTAL_BYTES_LIMIT;
        $this->total_bytes_read = $end - $start;
    }

    private function advance($amount)
    {
        $this->current += $amount;
    }

    public function bufferSize()
    {
        return $this->buffer_end - $this->current;
    }

    public function current()
    {
        return $this->total_bytes_read -
            ($this->buffer_end - $this->current +
            $this->buffer_size_after_limit);
    }

    public function substr($start, $end)
    {
        return substr($this->buffer, $start, $end - $start);
    }

    private function recomputeBufferLimits()
    {
        $this->buffer_end += $this->buffer_size_after_limit;
        $closest_limit = min($this->current_limit, $this->total_bytes_limit);
        if ($closest_limit < $this->total_bytes_read) {
            
            
            $this->buffer_size_after_limit = $this->total_bytes_read -
                $closest_limit;
            $this->buffer_end -= $this->buffer_size_after_limit;
        } else {
            $this->buffer_size_after_limit = 0;
        }
    }

    private function consumedEntireMessage()
    {
        return $this->legitimate_message_end;
    }

    /**
     * Read uint32 into $var. Advance buffer with consumed bytes. If the
     * contained varint is larger than 32 bits, discard the high order bits.
     * @param $var
     */
    public function readVarint32(&$var)
    {
        if (!$this->readVarint64($var)) {
            return false;
        }

        if (PHP_INT_SIZE == 4) {
            $var = bcmod($var, 4294967296);
        } else {
            $var &= 0xFFFFFFFF;
        }

        
        if ($var > 0x7FFFFFFF) {
            if (PHP_INT_SIZE === 8) {
                $var = $var | (0xFFFFFFFF << 32);
            } else {
                $var = bcsub($var, 4294967296);
            }
        }

        $var = intval($var);
        return true;
    }

    /**
     * Read Uint64 into $var. Advance buffer with consumed bytes.
     * @param $var
     */
    public function readVarint64(&$var)
    {
        $count = 0;

        if (PHP_INT_SIZE == 4) {
            $high = 0;
            $low = 0;
            $b = 0;

            do {
                if ($this->current === $this->buffer_end) {
                    return false;
                }
                if ($count === self::MAX_VARINT_BYTES) {
                    return false;
                }
                $b = ord($this->buffer[$this->current]);
                $bits = 7 * $count;
                if ($bits >= 32) {
                    $high |= (($b & 0x7F) << ($bits - 32));
                } else if ($bits > 25){
                    
                    $low |= (($b & 0x7F) << 28);
                    $high = ($b & 0x7F) >> 4;
                } else {
                    $low |= (($b & 0x7F) << $bits);
                }

                $this->advance(1);
                $count += 1;
            } while ($b & 0x80);

            $var = GPBUtil::combineInt32ToInt64($high, $low);
            if (bccomp($var, 0) < 0) {
                $var = bcadd($var, "18446744073709551616");
            }
        } else {
            $result = 0;
            $shift = 0;

            do {
                if ($this->current === $this->buffer_end) {
                    return false;
                }
                if ($count === self::MAX_VARINT_BYTES) {
                    return false;
                }

                $byte = ord($this->buffer[$this->current]);
                $result |= ($byte & 0x7f) << $shift;
                $shift += 7;
                $this->advance(1);
                $count += 1;
            } while ($byte > 0x7f);

            $var = $result;
        }

        return true;
    }

    /**
     * Read int into $var. If the result is larger than the largest integer, $var
     * will be -1. Advance buffer with consumed bytes.
     * @param $var
     */
    public function readVarintSizeAsInt(&$var)
    {
        if (!$this->readVarint64($var)) {
            return false;
        }
        $var = (int)$var;
        return true;
    }

    /**
     * Read 32-bit unsigned integer to $var. If the buffer has less than 4 bytes,
     * return false. Advance buffer with consumed bytes.
     * @param $var
     */
    public function readLittleEndian32(&$var)
    {
        $data = null;
        if (!$this->readRaw(4, $data)) {
            return false;
        }
        $var = unpack('V', $data);
        $var = $var[1];
        return true;
    }

    /**
     * Read 64-bit unsigned integer to $var. If the buffer has less than 8 bytes,
     * return false. Advance buffer with consumed bytes.
     * @param $var
     */
    public function readLittleEndian64(&$var)
    {
        $data = null;
        if (!$this->readRaw(4, $data)) {
            return false;
        }
        $low = unpack('V', $data)[1];
        if (!$this->readRaw(4, $data)) {
            return false;
        }
        $high = unpack('V', $data)[1];
        if (PHP_INT_SIZE == 4) {
            $var = GPBUtil::combineInt32ToInt64($high, $low);
        } else {
            $var = ($high << 32) | $low;
        }
        return true;
    }

    /**
     * Read tag into $var. Advance buffer with consumed bytes.
     */
    public function readTag()
    {
        if ($this->current === $this->buffer_end) {
            
            
            
            $current_position = $this->total_bytes_read -
                $this->buffer_size_after_limit;
            if ($current_position >= $this->total_bytes_limit) {
                
                
                $this->legitimate_message_end =
                    ($this->current_limit === $this->total_bytes_limit);
            } else {
                $this->legitimate_message_end = true;
            }
            return 0;
        }

        $result = 0;
        
        $success = $this->readVarint32($result);
        if ($success) {
            return $result;
        } else {
            return 0;
        }
    }

    public function readRaw($size, &$buffer)
    {
        $current_buffer_size = 0;
        
        if ($size < 0 || $this->bufferSize() < $size) {
            return false;
        }

        if ($size === 0) {
          $buffer = "";
        } else {
          $buffer = substr($this->buffer, $this->current, $size);
          $this->advance($size);
        }

        return true;
    }

    
    public function pushLimit($byte_limit)
    {
        
        $current_position = $this->current();
        $old_limit = $this->current_limit;

        
        
        if ($byte_limit >= 0 &&
            $byte_limit <= PHP_INT_MAX - $current_position &&
            $byte_limit <= $this->current_limit - $current_position) {
            $this->current_limit = $current_position + $byte_limit;
            $this->recomputeBufferLimits();
        } else {
            throw new GPBDecodeException("Fail to push limit.");
        }

        return $old_limit;
    }

    
    public function popLimit($byte_limit)
    {
        $this->current_limit = $byte_limit;
        $this->recomputeBufferLimits();
        
        
        $this->legitimate_message_end = false;
    }

    public function incrementRecursionDepthAndPushLimit(
        $byte_limit, &$old_limit, &$recursion_budget)
    {
        $old_limit = $this->pushLimit($byte_limit);
        $recursion_budget = --$this->recursion_budget;
    }

    public function decrementRecursionDepthAndPopLimit($byte_limit)
    {
        $result = $this->consumedEntireMessage();
        $this->popLimit($byte_limit);
        ++$this->recursion_budget;
        return $result;
    }

    public function bytesUntilLimit()
    {
        if ($this->current_limit === PHP_INT_MAX) {
            return -1;
        }
        return $this->current_limit - $this->current;
    }
}
