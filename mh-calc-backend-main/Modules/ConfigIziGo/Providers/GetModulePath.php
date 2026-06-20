<?php

namespace Modules\ConfigIziGo\Providers;

trait GetModulePath
{
    protected ?string $modulePath = null;

    protected function path(string $target): string
    {
        if (!$this->modulePath) {
            $this->modulePath = app('modules')->find($this->moduleName)->getPath();
        }

        return "{$this->modulePath}/$target";
    }
}
