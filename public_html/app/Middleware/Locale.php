<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

class Locale
{
    protected Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function handle(Request $request, callable $next) : ?Response
    {
        $translationService = $this->application->get('translationService');
        $preferredLanguage = $_SESSION['preferred_language']
            ?? $this->getBrowserLocale() 
            ?? $translationService->getFallbackLanguage();

        if (!array_key_exists($preferredLanguage, $translationService->getSupportedLanguages())) {
            $preferredLanguage = $translationService->getFallbackLanguage();
        }
        $translationService->setLocale($preferredLanguage);

        return $next($request);
    }

    private function getBrowserLocale() : ?string
    {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            return substr($langs[0], 0, 2);
        }
        return null;
    }
}
