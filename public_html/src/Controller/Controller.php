<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;

class Controller
{
    protected Application $application;
    protected \Twig\Environment $twig;
    protected array $twigVariables = [];
    private bool $redirect = false;
    
    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->twig = $this->application->get('twig');
        $this->twigVariables = ['localeKey' => $this->getLocaleKey()];
    }
    
    public function __destruct()
    {
        if (!$this->redirect) {
            $this->application->get('sessionService')->set('PREV_ROUTE', REQUEST_URI);
        }
    }

    public function __invoke(\app\Http\Request $request): string
    {
        if (strpos($cls = $this::class, $rpl = SRC_CONTROLLER) !== false) {
            $view = strtolower(str_replace($rpl, '', $cls));
            if (file_exists(DOC_ROOT.'/src/View/'.$view.'.twig')) {
                return $this->twig->render($view.'.twig', $this->twigVariables);
            }
        }
        print_r('<pre>');
        var_dump($request);
        print_r('</pre>');
        return (string) "";
    }
    
    private function getLocaleKey(): int
    {
        $sessionService = $this->application->get('sessionService');
        $translationService = $this->application->get('translationService');
        $supportedLanguages = $translationService->getSupportedLanguages();
        $languages = [];
        foreach (array_keys($supportedLanguages) as $key) {
            $languages[] = $key;
        }
        return (int)array_search($sessionService->get('preferred_language', $translationService->getFallbackLocale()), $languages);
    }
}
