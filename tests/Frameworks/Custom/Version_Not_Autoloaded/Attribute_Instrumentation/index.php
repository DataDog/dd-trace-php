<?php

spl_autoload_register(function() {
    class TracedClass
    {
        #[DDTrace\Trace(tags: ["mode" => "class"])]
        public static function func() {
            echo "Traced Class\n";
        }
    }
});

#[DDTrace\Trace(tags: ["mode" => "func"])]
function traced() {
    echo "Traced Function\n";
}

traced();
TracedClass::func();
