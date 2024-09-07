<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;

class Controller
{
    /**
     * Summary of application
     * @var Application
     */
    protected Application $application;

    /**
     * Summary of twig
     * @var \Twig\Environment
     */
    protected \Twig\Environment $twig;

    /**
     * Summary of twigVariables
     * @var array
     */
    protected array $twigVariables = [];

    /**
     * Summary of redirect
     * @var bool
     */
    private bool $redirect = false;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->twig = $this->application->get('twig');
        $this->twigVariables = [
            'localeKey' => $this->getLocaleKey(),
            'translation' => $this->application->get('translationService'),
        ];
    }

    public function __destruct()
    {
        if ($this->redirect === false) {
            $this->application->get('sessionService')->set('PREV_ROUTE', REQUEST_URI);
        }
    }

    /**
     * Summary of __invoke
     * @param \app\Http\Request $request
     * @return string|null
     */
    public function __invoke(\app\Http\Request $request): string|null
    {
        if (strpos($cls = $this::class, $rpl = SRC_CONTROLLER) !== false) {
            $view = strtolower(str_replace($rpl, '', $cls));
            if (file_exists(DOC_ROOT.'/src/View/'.$view.'.twig') === true) {
                return (string) $this->twig->render($view.'.twig', $this->twigVariables);
            }
        }

        print_r('<pre>');
        var_dump($request);
        print_r('</pre>');
    }

    /**
     * Summary of getLocaleKey
     * @return int
     */
    private function getLocaleKey(): int
    {
        $sessionService = $this->application->get('sessionService');
        $translationService = $this->application->get('translationService');
        $supportedLanguages = $translationService->getSupportedLanguages();
        $languages = [];
        foreach (array_keys($supportedLanguages) as $key) {
            $languages[] = $key;
        }

        return (int) array_search($sessionService->get('preferred_language', $translationService->getFallbackLocale()), $languages);
    }
}
