--TEST--
_convert_xml function (max depth exceeded)
--FILE--
<?php
// Generate XML with 35 levels of nesting (exceeds MAX_XML_DEPTH of 30)
$depth = 35;
$xml = '';
for ($i = 1; $i <= $depth; $i++) {
    $xml .= "<level$i>";
}
$xml .= "deep content";
for ($i = $depth; $i >= 1; $i--) {
    $xml .= "</level$i>";
}

$content_type = "application/xml";

$result = datadog\appsec\testing\convert_xml($xml, $content_type);

echo(json_encode($result, JSON_PRETTY_PRINT));
--EXPECTF--
{
    "level1": [
        {
            "level2": [
                {
                    "level3": [
                        {
                            "level4": [
                                {
                                    "level5": [
                                        {
                                            "level6": [
                                                {
                                                    "level7": [
                                                        {
                                                            "level8": [
                                                                {
                                                                    "level9": [
                                                                        {
                                                                            "level10": [
                                                                                {
                                                                                    "level11": [
                                                                                        {
                                                                                            "level12": [
                                                                                                {
                                                                                                    "level13": [
                                                                                                        {
                                                                                                            "level14": [
                                                                                                                {
                                                                                                                    "level15": [
                                                                                                                        {
                                                                                                                            "level16": [
                                                                                                                                {
                                                                                                                                    "level17": [
                                                                                                                                        {
                                                                                                                                            "level18": [
                                                                                                                                                {
                                                                                                                                                    "level19": [
                                                                                                                                                        {
                                                                                                                                                            "level20": [
                                                                                                                                                                {
                                                                                                                                                                    "level21": [
                                                                                                                                                                        {
                                                                                                                                                                            "level22": [
                                                                                                                                                                                {
                                                                                                                                                                                    "level23": [
                                                                                                                                                                                        {
                                                                                                                                                                                            "level24": [
                                                                                                                                                                                                {
                                                                                                                                                                                                    "level25": [
                                                                                                                                                                                                        {
                                                                                                                                                                                                            "level26": [
                                                                                                                                                                                                                {
                                                                                                                                                                                                                    "level27": [
                                                                                                                                                                                                                        {
                                                                                                                                                                                                                            "level28": [
                                                                                                                                                                                                                                {
                                                                                                                                                                                                                                    "level29": [
                                                                                                                                                                                                                                        {
                                                                                                                                                                                                                                            "level30": [
                                                                                                                                                                                                                                                {
                                                                                                                                                                                                                                                    "level31": []
                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                            ]
                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                    ]
                                                                                                                                                                                                                                }
                                                                                                                                                                                                                            ]
                                                                                                                                                                                                                        }
                                                                                                                                                                                                                    ]
                                                                                                                                                                                                                }
                                                                                                                                                                                                            ]
                                                                                                                                                                                                        }
                                                                                                                                                                                                    ]
                                                                                                                                                                                                }
                                                                                                                                                                                            ]
                                                                                                                                                                                        }
                                                                                                                                                                                    ]
                                                                                                                                                                                }
                                                                                                                                                                            ]
                                                                                                                                                                        }
                                                                                                                                                                    ]
                                                                                                                                                                }
                                                                                                                                                            ]
                                                                                                                                                        }
                                                                                                                                                    ]
                                                                                                                                                }
                                                                                                                                            ]
                                                                                                                                        }
                                                                                                                                    ]
                                                                                                                                }
                                                                                                                            ]
                                                                                                                        }
                                                                                                                    ]
                                                                                                                }
                                                                                                            ]
                                                                                                        }
                                                                                                    ]
                                                                                                }
                                                                                            ]
                                                                                        }
                                                                                    ]
                                                                                }
                                                                            ]
                                                                        }
                                                                    ]
                                                                }
                                                            ]
                                                        }
                                                    ]
                                                }
                                            ]
                                        }
                                    ]
                                }
                            ]
                        }
                    ]
                }
            ]
        }
    ]
}
