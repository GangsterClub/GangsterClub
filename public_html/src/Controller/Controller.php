<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;

class Controller
{
    private bool $redirect = false;
    protected Application $application;
    protected \Twig\Environment $twig;
    protected array $twigVariables = [];

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->twig = $this->application->get('twig');
        $this->twigVariables = ['localeKey' => $this->getLocaleKey()];
    }
    
    public function __destruct()
    {
        if(!$this->redirect)
            $this->application->get('sessionService')->set('PREV_ROUTE', REQUEST_URI);
    }

    public function __invoke(\app\Http\Request $request) : string
    {
        $cls = $this::class;
        if(strpos($cls, $rpl = SRC_CONTROLLER) !== false)
        {
            if(defined('DOC_ROOT'))
            {
                $view = strtolower(str_replace($rpl, '', $cls));
                if(file_exists(DOC_ROOT.'/src/View/'.$view.'.twig'))
                    return $this->twig->render($view.'.twig', $this->twigVariables);
            }
        }
        print_r('<pre>');
        var_dump($request);
        print_r('</pre>');
        return (string) "";
    }
    
    private function getLocaleKey()
    {
        $sessionService = $this->application->get('sessionService');
        $translationService = $this->application->get('translationService');
        $supportedLanguages = $translationService->getSupportedLanguages();
        $languages = [];
        foreach($supportedLanguages as $key => $value)
            $languages[] = $key;

        return array_search($sessionService->get('preferred_language', $translationService->getFallbackLocale()), $languages);
    }
}
