<?php
class ZendAbstractInterfaceCallStub {
    /* not refcounted in real PHP */
    public function returnLong() {
        return 42;
    }

    /* refcounted */
    public function returnObject() {
        return $this;
    }

    public function acceptLong($long) {
        return $long;
    }

    public function acceptObject($object) {
        return $object;
    }

    public function throwsException() {
        throw new RuntimeException();
    }

    public static function declaredStatic() {
        return __METHOD__;
    }

    public function notDeclaredStatic() {
        return __METHOD__;
    }
}
?>
