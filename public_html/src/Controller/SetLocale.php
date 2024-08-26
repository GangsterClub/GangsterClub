<?PHP

declare(strict_types=1);

namespace src\Controller;

class SetLocale extends Controller
{
    private bool $redirect = true;
    
    public function __invoke(\app\Http\Request $request) : string
    {
        $locale = $request->getParameter('locale') ?? null;
        if($locale !== null)
            $locale = urldecode($locale);

        $translationService = $this->application->get('translationService');
        if(array_key_exists($locale, $translationService->getSupportedLanguages()))
        {
            $translationService->setLocale($locale);
            $_SESSION['preferred_language'] = $locale;
        }
        return header('Location: ' . filter_var($_SESSION['PREV_ROUTE'], FILTER_SANITIZE_URL),  true, 301);
    }
}
