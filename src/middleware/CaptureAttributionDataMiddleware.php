<?php

namespace Kyranb\Footprints\Middleware;

use Auth;
use Closure;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kyranb\Footprints\Visit;

class CaptureAttributionDataMiddleware
{
    /**
     * The Request instance.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * The Request instance.
     *
     * @var \Illuminate\Http\Resonse
     */
    protected $response;

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $this->request = $request;

        $this->response = $next($this->request);

        //Only track get requests
        if (!$this->request->isMethod('get')) {
            return $this->response;
        }

        if ($this->disableOnAuthentication()) {
            return $this->response;
        }

        if ($this->disableInternalLinks()) {
            return $this->response;
        }

        $attributionData = $this->captureAttributionData();
        $cookieToken = $this->findOrCreateTrackingCookieToken();

        $this->trackVisit($attributionData, $cookieToken);

        return $this->response;
    }

    /**
     * @return bool
     */
    protected function disableOnAuthentication()
    {
        if (Auth::check() && config('footprints.disable_on_authentication')) {
            return true;
        }
    }

    /**
     * @return bool
     */
    protected function disableInternalLinks()
    {
        if (!config('footprints.disable_internal_links')) {
            return false;
        }

        $referrer_domain = parse_url($this->request->headers->get('referer'));
        $referrer_domain = !isset($referrer_domain['host']) ? null : $referrer_domain['host'];
        $request_domain = $this->request->server('SERVER_NAME');

        if (!empty($referrer_domain) && $referrer_domain == $request_domain) {
            return true;
        }
    }

    /**
     * @return array
     */
    protected function captureAttributionData()
    {
        $attributionData = [];

        $attributionData['landing_domain']  = $this->captureLandingDomain();
        $attributionData['landing_page']    = $this->captureLandingPage();
        $attributionData['referrer']        = $this->captureReferrer();
        $attributionData['utm']             = $this->captureUTM();
        $attributionData['referral']        = $this->captureReferral();
        $attributionData['custom']          = $this->getCustomParameter();

        return $attributionData;
    }

    /**
     * @return array
     */
    protected function getCustomParameter()
    {
        $arr = [];

        if (config('footprints.custom_parameters')) {
            foreach (config('footprints.custom_parameters') as $parameter) {
                $arr[$parameter] = $this->request->input($parameter);
            }
        }

        return $arr;
    }

    /**
     * @return string
     */
    protected function captureLandingDomain()
    {
        return $this->request->server('SERVER_NAME');
    }

    /**
     * @return string
     */
    protected function captureLandingPage()
    {
        return $this->request->path();
    }

    /**
     * @return array
     */
    protected function captureUTM()
    {
        $parameters = ['utm_source', 'utm_campaign', 'utm_medium', 'utm_term', 'utm_content'];

        $utm = [];

        foreach ($parameters as $parameter) {
            if ($this->request->has($parameter)) {
                $utm[$parameter] = $this->request->input($parameter);
            } else {
                $utm[$parameter] = null;
            }
        }

        return $utm;
    }

    /**
     * @return array
     */
    protected function captureReferrer()
    {
        $referrer = [];

        $referrer['referrer_url'] = $this->request->headers->get('referer');

        $parsedUrl = parse_url($referrer['referrer_url']);

        $referrer['referrer_domain'] = isset($parsedUrl['host']) ? $parsedUrl['host'] : null;

        return $referrer;
    }

    /**
     * @return string
     */
    protected function captureReferral()
    {
        return $this->request->input('ref');

    }

    /**
     * @param array $attributionData
     *
     * @return int $id The id of the visit in the database
     */
    protected function trackVisit($attributionData, $cookieToken)
    {
        $visit = Visit::create(array_merge([
            
            'cookie_token'      => $cookieToken,
            'landing_domain'    => $attributionData['landing_domain'],
            'landing_page'      => $attributionData['landing_page'],
            'referrer_domain'   => $attributionData['referrer']['referrer_domain'],
            'referrer_url'      => $attributionData['referrer']['referrer_url'],
            'utm_source'        => $attributionData['utm']['utm_source'],
            'utm_campaign'      => $attributionData['utm']['utm_campaign'],
            'utm_medium'        => $attributionData['utm']['utm_medium'],
            'utm_term'          => $attributionData['utm']['utm_term'],
            'utm_content'       => $attributionData['utm']['utm_content'],
            'referral'          => $attributionData['referral'],
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ], $attributionData['custom']));

        return $visit->id;
    }

    /**
     * @return string $cookieToken
     */
    protected function findOrCreateTrackingCookieToken()
    {
        $cookieToken = str_random(40);

        if ($this->request->hasCookie(config('footprints.cookie_name'))) {
            $cookieToken = $this->request->cookie(config('footprints.cookie_name'));
        }

        if (method_exists($this->response, "withCookie")) {
            $this->response->withCookie(cookie(config('footprints.cookie_name'), $cookieToken, config('footprints.attribution_duration')));
        }

        return $cookieToken;
    }
}
