<?php

namespace GPBMetadata\Google\Type;

class CalendarPeriod
{
    public static $is_initialized = false;

    public static function initOnce() {
        $pool = \Google\Protobuf\Internal\DescriptorPool::getGeneratedPool();

        if (static::$is_initialized == true) {
          return;
        }
        $pool->internalAddGeneratedFile(
            "\x0A\xB3\x02\x0A!google/type/calendar_period.proto\x12\x0Bgoogle.type*\x7F\x0A\x0ECalendarPeriod\x12\x1F\x0A\x1BCALENDAR_PERIOD_UNSPECIFIED\x10\x00\x12\x07\x0A\x03DAY\x10\x01\x12\x08\x0A\x04WEEK\x10\x02\x12\x0D\x0A\x09FORTNIGHT\x10\x03\x12\x09\x0A\x05MONTH\x10\x04\x12\x0B\x0A\x07QUARTER\x10\x05\x12\x08\x0A\x04HALF\x10\x06\x12\x08\x0A\x04YEAR\x10\x07Bx\x0A\x0Fcom.google.typeB\x13CalendarPeriodProtoP\x01ZHgoogle.golang.org/genproto/googleapis/type/calendarperiod;calendarperiod\xA2\x02\x03GTPb\x06proto3"
        , true);

        static::$is_initialized = true;
    }
}

