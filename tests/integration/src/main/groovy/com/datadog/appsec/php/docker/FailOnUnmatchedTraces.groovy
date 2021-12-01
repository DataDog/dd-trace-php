package com.datadog.appsec.php.docker

import org.junit.jupiter.api.extension.ExtendWith

import java.lang.annotation.ElementType
import java.lang.annotation.Retention
import java.lang.annotation.RetentionPolicy
import java.lang.annotation.Target

@Target(ElementType.FIELD)
@Retention(RetentionPolicy.RUNTIME)
@ExtendWith(FailOnUnmatchedTracesExtension)
@interface FailOnUnmatchedTraces {
}
