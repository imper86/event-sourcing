<?php


namespace Ecotone\EventSourcing\Config;


class ProjectionChannelAdapter
{
    public function run() : bool
    {
//        This is executed by channel adapter, which then follows to execute.
//        It allows for intercepting messaging handling (e.g. transaction management).
        return true;
    }
}