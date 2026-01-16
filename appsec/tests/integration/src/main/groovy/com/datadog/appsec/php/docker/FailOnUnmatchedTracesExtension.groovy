package com.datadog.appsec.php.docker

import org.junit.jupiter.api.extension.AfterEachCallback
import org.junit.jupiter.api.extension.ExtensionContext
import org.junit.platform.commons.support.AnnotationSupport

import java.lang.reflect.Field

class FailOnUnmatchedTracesExtension implements AfterEachCallback {
   @Override
    void afterEach(ExtensionContext context) throws Exception {
       context.getTestInstance().ifPresent(testInstance -> {
           List<Field> containerFields =
                   AnnotationSupport.findAnnotatedFields(testInstance.getClass(), FailOnUnmatchedTraces)
           containerFields.each {f ->
               def container = f.get(testInstance)
               if (!(container instanceof AppSecContainer)) {
                   throw new RuntimeException(
                           '@FailOnUnmatchedTraces can only be applied to AppSecContainer fields')
               }

               if (context.executionException.present) {
                   container.clearTraces()
               } else {
                   container.assertNoTraces()
               }
           }
       });
   }
}
