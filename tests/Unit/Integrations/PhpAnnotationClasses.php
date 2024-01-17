<?php
namespace DDTrace\Tests\Unit\Integrations\Annotations {

    use Symfony\Component\Routing\Annotation\Route;

    class PhpActionAnnotationsController
    {
        #[Route("/basic-path", name: "basic action")]
        public function basicAction()
        {
        }

        #[Route("/missing-name")]
        public function missingName()
        {
        }

        #[Route(name: "only the name")]
        public function nothingButName()
        {
        }

        #[Route("/dynamic-path/{argument}", name: "action with dynamic arguments")]
        public function actionWithArguments($argument)
        {
        }

        #[Route(path: ['en' => '/localized-action-en', 'nl' => '/localized-action-nl'], name: 'localized method')]
        public function localizedAction()
        {
        }

        #[Route("/hello/{name<\w+>}", name: "hello_without_default")]
        #[Route("/hello/{name<\w+>?Symfony}", name: "hello_with_default")]
        public function multipleRoutesAction()
        {
        }
    }

    #[Route("/invokable", name:"lol")]
    class InvokableController
    {
        public function __invoke()
        {
        }
    }

    #[Route(path: ['en' => '/invokable-en', 'nl' => '/invokable-nl'], name: 'invokable localized')]
    class InvokableLocalizedController
    {
        public function __invoke()
        {
            return new Response('Hi!');
        }
    }

    #[Route("/the/path")]
    class MethodActionControllers
    {
        #[Route(name: "post", methods:["POST"])]
        public function post()
        {
        }

        #[Route(name: "put", methods:["PUT"], priority:10)]
        public function put()
        {
        }
    }

    #[Route(path: ['en' => '/the/path', 'nl' => '/het/pad'])]
    class LocalizedMethodActionControllers
    {
        #[Route(name: "post", methods:["POST"])]
        public function post()
        {
        }

        #[Route(name: "put", methods:["PUT"], priority:10)]
        public function put()
        {
        }
    }

    #[Route("/defaults", locale:"g_locale", format:"g_format")]
    class GlobalDefaultsClass
    {

        #[Route("/specific-locale", name:"specific_locale", locale:"s_locale")]
        public function locale()
        {
        }


        #[Route("/specific-format", name:"specific_format", format:"s_format")]
        public function format()
        {
        }
    }

    #[Route("/prefix", host:"example.com", condition:"lol=fun")]
    class PrefixedActionPathController
    {
        #[Route("/path", name:"action")]
        public function action()
        {
        }
    }

    #[Route("/prefix")]
    class PrefixedActionLocalizedRouteController
    {

        #[Route(path: ["en" => "/path", "nl" => "/pad"], name:"action")]
        public function action()
        {
        }
    }

    #[Route(path: ["nl" => "/nl", "en" => "/en"])]
    class LocalizedPrefixLocalizedActionController
    {

        #[Route(path: ["nl" => "/actie", "en" => "/action"], name:"action")]
        public function action()
        {
        }
    }

    #[Route("/1", name:"route1", schemes: ["https"], methods: ["GET"])]
    #[Route("/2", name:"route2", schemes: ["https"], methods: ["GET"])]
    class BazClass
    {
        public function __invoke()
        {
        }
    }

    #[Route(path: ["en" => "/en", "nl" => "/nl"])]
    class LocalizedPrefixWithRouteWithoutLocale
    {

        #[Route("/suffix", name: "action")]
        public function action()
        {
        }
    }

} //Namespace annotations

namespace DDTrace\Tests\Unit\Integrations\DocBlocks {
    use Symfony\Component\Routing\Annotation\Route;

    class PhpActionAnnotationsController
    {
        /**
        * @Route("/basic-path", name="basic action")
        */
        public function basicAction()
        {
        }

        /**
         * @Route("/missing-name")
         */
        public function missingName()
        {
        }

        /**
         * @Route(name= "only the name")
         */
        public function nothingButName()
        {
        }

        /**
         * @Route("/dynamic-path/{argument}", name= "action with dynamic arguments")
         */
        public function actionWithArguments($argument)
        {
        }

        /**
         * @Route(path= {"en" : "/localized-action-en", "nl" : "/localized-action-nl"}, name= "localized method")
         */
        public function localizedAction()
        {
        }

        /**
         * @Route("/hello/{name<\w+>}", name= "hello_without_default")
         * @Route("/hello/{name<\w+>?Symfony}", name= "hello_with_default")
         */
        public function multipleRoutesAction()
        {
        }
    }

    /**
     * @Route("/invokable", name="lol")
     */
    class InvokableController
    {
        public function __invoke()
        {
        }
    }

    /**
     * @Route({"en":"/invokable-en", "nl": "/invokable-nl"}, name="invokable localized")
     */
    class InvokableLocalizedController
    {
        public function __invoke()
        {
            return new Response('Hi!');
        }
    }

    /**
     * @Route("/the/path")
     */
    class MethodActionControllers
    {
        /**
         * @Route(name="post", methods={"POST"})
         */
        public function post()
        {
        }

        /**
         * @Route(name="put", methods={"PUT"}, priority=10)
         */
        public function put()
        {
        }
    }

    /**
     * @Route(path= {"en" : "/the/path", "nl" : "/het/pad"})
     */
    class LocalizedMethodActionControllers
    {
        /**
         * @Route(name= "post", methods={"POST"})
         */
        public function post()
        {
        }

        /**
         * @Route(name= "put", methods={"PUT"}, priority=10)
         */
        public function put()
        {
        }
    }

    /**
     * @Route("/defaults", locale="g_locale", format="g_format")
     */
    class GlobalDefaultsClass
    {

        /**
         * @Route("/specific-locale", name="specific_locale", locale="s_locale")
         */
        public function locale()
        {
        }


        /**
         * @Route("/specific-format", name="specific_format", format="s_format")
         */
        public function format()
        {
        }
    }

    /**
     * @Route("/prefix", host="example.com", condition="lol=fun")
     */
    class PrefixedActionPathController
    {
        /**
         * @Route("/path", name="action")
         */
        public function action()
        {
        }
    }

    /**
     * @Route("/prefix")
     */
    class PrefixedActionLocalizedRouteController
    {

        /**
         * @Route(path= {"en" : "/path", "nl" : "/pad"}, name="action")
         */
        public function action()
        {
        }
    }

    /**
     * @Route({"nl":"/nl", "en":"/en"})
     */
    class LocalizedPrefixLocalizedActionController
    {

        /**
         * @Route(path= {"nl" : "/actie", "en" : "/action"}, name="action")
         */
        public function action()
        {
        }
    }

    /**
     * @Route("/1", name="route1", schemes={"https"}, methods={"GET"})
     * @Route("/2", name="route2", schemes={"https"}, methods={"GET"})
     */
    class BazClass
    {
        public function __invoke()
        {
        }
    }

    /**
     * @Route(path= {"en" : "/en", "nl" : "/nl"})
     */
    class LocalizedPrefixWithRouteWithoutLocale
    {

        /**
         * @Route("/suffix", name= "action")
         */
        public function action()
        {
        }
    }

} //Namespace docblocks
