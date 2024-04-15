<?php

namespace DDTrace\Util;

class PluginTimes {
    public $cumulative;
    public $total;
    public $activePlugins;
    public $lastPlugin;
    public $stack;
    public $calls;

    public function __construct() {
        $this->cumulative = [];
        $this->total = [];
        $this->activePlugins = [];
        $this->lastPlugin = "";
        $this->stack = [];
        $this->calls = [];
    }


    public function openSpan($pluginName) {
        $this->activePlugins[$pluginName] = ($this->activePlugins[$pluginName] ?? 0) + 1;
        $this->stack[] = [$pluginName, $this->lastPlugin];
        $this->lastPlugin = $pluginName;
        $this->calls[$pluginName] = ($this->calls[$pluginName] ?? 0) + 1;
    }


    public function closeSpan($duration) {
        if (empty($this->stack)) {
            return;
        }

        list($pluginName, $parentPlugin) = array_pop($this->stack);
        $this->activePlugins[$pluginName] = $this->activePlugins[$pluginName] - 1;
        if ($this->activePlugins[$pluginName] == 0) {
            $this->cumulative[$pluginName] = ($this->cumulative[$pluginName] ?? 0) + $duration;
            $this->total[$pluginName] = ($this->total[$pluginName] ?? 0) + $duration;
            if ($parentPlugin) {
                $this->total[$parentPlugin] = ($this->total[$parentPlugin] ?? 0) - $duration;
            }
        }
        $this->lastPlugin = $parentPlugin;
    }
}

