package com.datadog.appsec.php.docker

import com.datadog.appsec.php.mock_agent.MockDatadogAgent
import org.testcontainers.containers.GenericContainer

class InspectContainerHelper {
    static run(AppSecContainer container, GenericContainer... extraContainers) {
        MockDatadogAgent agent = new MockDatadogAgent()
        agent.start()

        for (GenericContainer extraContainer : extraContainers) {
            extraContainer.start()
        }

        container.start()
        System.sleep 1_000 // so our output more likely shows at the bottom
        System.out.println "Server available at ${container.buildURI('/')}"
        System.out.println "Inspect container with docker exec -it ${container.getContainerId()} /bin/bash"
        System.out.println "Press ENTER to stop container"
        System.in.read()
        container.stop()

        for (GenericContainer extraContainer : extraContainers) {
            extraContainer.stop()
        }

        agent.stop()
    }
}
