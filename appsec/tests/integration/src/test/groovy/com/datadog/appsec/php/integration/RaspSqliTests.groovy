package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Span
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.junit.jupiter.params.ParameterizedTest
import org.junit.jupiter.params.provider.Arguments
import org.junit.jupiter.params.provider.MethodSource
import org.testcontainers.containers.MySQLContainer
import org.testcontainers.containers.Network
import org.testcontainers.containers.wait.strategy.Wait
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse
import java.util.stream.Stream

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static com.datadog.appsec.php.integration.TestParams.phpVersionAtLeast
import static org.junit.jupiter.api.Assumptions.assumeTrue

@Testcontainers
@Slf4j
@TestMethodOrder(MethodOrderer.OrderAnnotation)
@EnabledIf('isEnabled')
class RaspSqliTests {
    static boolean enabled = variant.contains('zts') && phpVersion == '8.4' ||
            !variant.contains('zts') && phpVersion == '7.0'

    private static Network network = Network.newNetwork()

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'nginx-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )
                    .withNetwork(network) as AppSecContainer


    @Container
    private static MySQLContainer MYSQL = new MySQLContainer('mysql:5.7')
            .withDatabaseName('testdb')
            .withUsername('testuser')
            .withPassword('testpass')
            .withInitScript('init.sql')
            .withNetwork(network)
            .withNetworkAliases('mysql')
            .waitingFor(Wait.forLogMessage(".*ready for connections.*", 1)) as MySQLContainer

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER, MYSQL)
    }

    static Stream<Arguments> getPdoFunctions() {
        return Stream.of(
                Arguments.of("query", "1", false),
                Arguments.of("query", "1 OR 1=1", true),
                Arguments.of("prepare", "x", false),
                Arguments.of("prepare", "x' OR '1'='1", true),
                Arguments.of("exec", "1", false),
                Arguments.of("exec", "1; UPDATE users SET status='hacked'", true)
        )
    }

    @ParameterizedTest
    @MethodSource('getPdoFunctions')
    @Order(1)
    void 'test PDO SQL injection detection'(String function, String userInput, boolean isMalicious) {
        String dsn = "mysql:host=mysql;dbname=" + MYSQL.databaseName
        String username = MYSQL.username
        String password = MYSQL.password

        def encodedInput = URLEncoder.encode(userInput, "UTF-8")
        def url = "/rasp_sqli_pdo.php?dsn=${dsn}&username=${username}&password=${password}&function=${function}&user_input=${encodedInput}"

        def trace = CONTAINER.traceFromRequest(url) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def content = resp.body().text
            assert content.contains('OK')
            log.info('Response content: {}', content)
        }

        Span span = trace.first()
        assert span.metrics.'_dd.appsec.enabled' == 1.0d
        assert span.metrics.'_dd.appsec.waf.duration' > 0.0d
        assert span.metrics."_dd.appsec.rasp.rule.eval" >= 1.0d

        if (!isMalicious) {
            assert !span.meta.containsKey('_dd.appsec.json')
        } else {
            assert span.meta.containsKey('appsec.event') && span.meta.'appsec.event' == 'true'
            assert span.meta_struct.containsKey("_dd.stack")

            def appSecJson = span.parsedAppsecJson

            assert appSecJson.triggers[0].rule_matches[0].operator == 'sqli_detector'
            assert appSecJson.triggers[0].rule_matches[0].parameters[0].resource.value.size() > 1
            assert appSecJson.triggers[0].rule_matches[0].parameters[0].params.value == userInput
            assert appSecJson.triggers[0].rule_matches[0].parameters[0].db_type.value == 'mysql'
        }
    }


    static Stream<Arguments> getMysqliFunctions() {
        Stream.of(
                Arguments.of("query", "1", false),
                Arguments.of("query", "1 OR 1=1", true),
                Arguments.of("real_query", "1", false),
                Arguments.of("real_query", "1 UNION SELECT * FROM users", true),
                Arguments.of("prepare", "x", false),
                Arguments.of("prepare", "x' OR '1'='1", true),
                Arguments.of("procedural", "1", false),
                Arguments.of("procedural", "1 OR 1=1", true),
                Arguments.of("execute_query", "1", false),
                Arguments.of("execute_query", "1 OR 1=1", true),
                Arguments.of("multi_query", "1", false),
                Arguments.of("multi_query", "1; SELECT * FROM information_schema.tables", true)
        )
    }

    @ParameterizedTest
    @MethodSource('getMysqliFunctions')
    @Order(2)
    void 'test MySQLi SQL injection detection'(String function, String userInput, boolean isMalicious) {
        if (function == 'execute_query') {
            assumeTrue phpVersionAtLeast('8.2')
        }

        String host = "mysql"
        String dbname = MYSQL.databaseName
        String username = MYSQL.username
        String password = MYSQL.password

        def encodedInput = URLEncoder.encode(userInput, "UTF-8")
        def url = "/rasp_sqli_mysqli.php?host=${host}&dbname=${dbname}&username=${username}&password=${password}&function=${function}&user_input=${encodedInput}"

        def trace = CONTAINER.traceFromRequest(url) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def content = resp.body().text
            assert content.contains('OK')
            log.info('Response content: {}', content)
        }

        Span span = trace.first()
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.metrics."_dd.appsec.rasp.rule.eval" >= 1.0d

        if (!isMalicious) {
            assert !span.meta.containsKey('_dd.appsec.json')
        } else {
            assert span.meta.containsKey('appsec.event') && span.meta.'appsec.event' == 'true'
            assert span.meta_struct.containsKey("_dd.stack")
            def appSecJson = span.parsedAppsecJson
            assert appSecJson.triggers[0].rule_matches[0].operator == 'sqli_detector'
            assert appSecJson.triggers[0].rule_matches[0].parameters[0].resource.value.size() > 1
            assert appSecJson.triggers[0].rule_matches[0].parameters[0].params.value == userInput
            assert appSecJson.triggers[0].rule_matches[0].parameters[0].db_type.value == 'mysql'
        }
    }
}
