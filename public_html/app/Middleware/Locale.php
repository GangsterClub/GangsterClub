<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

class Locale
{
    /**
     * Summary of application
     * @var Application
     */
    protected Application $application;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    /**
     * Summary of handle
     * @param \app\Http\Request $request
     * @param callable $next
     * @return \app\Http\Response|object
     */
    public function handle(Request $request, callable $next): ?Response
    {
        $sessionService = $this->application->get('sessionService');
        $translationService = $this->application->get('translationService');
        $fallbackLocale = $translationService->getFallbackLocale();
        $preferredLanguage = ($sessionService->get('preferred_language', $this->getBrowserLocale()) ?? $fallbackLocale);
        if (array_key_exists($preferredLanguage, $translationService->getSupportedLanguages()) === false) {
            $preferredLanguage = $fallbackLocale;
        }

        $translationService->setLocale($preferredLanguage);
        $sessionService->set('preferred_language', $preferredLanguage);
        return $next($request);
    }

    /**
     * Summary of getBrowserLocale
     * @return string|null
     */
    private function getBrowserLocale(): ?string
    {
        $httpAcceptLang = filter_input(INPUT_SERVER, 'HTTP_ACCEPT_LANGUAGE', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (isset($httpAcceptLang) === true) {
            $langs = explode(',', $httpAcceptLang);
            return substr($langs[0], 0, 2);
        }

        return null;
    }
}
