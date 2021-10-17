<?php

/**
 * @category Class
 * @package  zheeknodev/roma
 * @author   ZheeknoDev <million8.me@gmail.com>
 * @license  https://opensource.org/licenses/MIT - MIT License 
 * @link     https://github.com/ZheeknoDev/Roma
 */

namespace Zheeknodev\Roma;

use Zheeknodev\Roma\Router\Request;
use Zheeknodev\Roma\Router\Response;
use Zheeknodev\Roma\Middleware\Middleware;

class Router
{
    private const REQUEST_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private $routeRequest;
    private $routeGroupMiddleware;
    private $routeGroupPrefix;

    private $request;
    private $response;

    public $middleware;
    public $controller;

    public function __construct()
    {
        $this->request = new Request;
        $this->response = new Response($this->request);
        $this->middleware = new Middleware(new Request);
    }

    public function __call($method, $arguments)
    {
        $method = strtoupper($method);
        $cond_1 = ($method == $this->request->requestMethod);
        $cond_2 = (in_array($method, self::REQUEST_METHODS));
        $cond_3 = (in_array($this->request->requestMethod, self::REQUEST_METHODS));
        if ($cond_1 && $cond_2 && $cond_3) {
            # verify csrf token for method POST
            $this->request->verifyCsrf();
            # call route function
            return call_user_func_array([$this, 'route'], $arguments);
        } 
    }

    /**
     * Dispatch the routes
     * @return void 
     */
    final public function dispatch()
    {
        # execute route's request
        if (!empty($this->routeRequest['callable'])) {
            # clear route's group prefix
            $this->routeGroupPrefix = null;
            # clear route's group middleware
            $this->routeGroupMiddleware = null;

            # call the middleware
            $condition_call_middleware_1 = !empty($this->middleware) ? 1 : 0;
            $condition_call_middleware_2 = !empty($this->routeRequest['middleware']) && is_array($this->routeRequest['middleware']) ? 1 : 0;
            if ($condition_call_middleware_1 && $condition_call_middleware_2) {
                foreach ($this->routeRequest['middleware'] as $middleware) {
                    $this->middleware->call($middleware);
                }
            }

            # execute callable
            echo call_user_func($this->routeRequest['callable']);
        } else {
            # 404 the request not found
            echo $this->onHttpError(404);
        }
    }

    /**
     * Grouping the routes
     * @param array $route ['prefix', 'middleware']
     * @param callable $callable
     */
    public function group(array $route, callable $callable)
    {
        if (!empty($route['prefix'])) {
            $this->routeGroupPrefix = $route['prefix'];
        }
        if (!empty($route['middleware'])) {
            $this->routeGroupMiddleware = $route['middleware'];
        }
        if (is_callable($callable)) {
            echo call_user_func($callable, $route['prefix']);
        }
    }

    /**
     * Call the middlewares to execute on the routes
     * @param array $middleware - list of the middlewares
     * @return void
     */
    public function middleware(array $middleware) : void
    {
        if (!empty($this->routeRequest['callable']) && empty($this->routeRequest['middleware'])) {
            $this->routeRequest['middleware'] = $middleware;
        }
    }

    /**
     * Response when the requests have something went wrong
     * @param int $code - define the HTTP response code to response
     * @param string|callable $callable
     */
    public function onHttpError(int $code = 404)
    {
        if (!empty($code)) {
            $this->response->returnJsonPattern->response = [
                'message' => $this->response->getResponseMessage($code)
            ];
            return $this->response->json($this->response->returnJsonPattern, $code);
        }
    }

    /**
     * Return the request class
     * @return object
     */
    final public function request()
    {
        return $this->request;
    }

    /**
     * Return the response class
     * @return object
     */
    final public function response()
    {
        return $this->response;
    }

    /**
     * Execute the method of routes
     * @param string $route
     * @param string|callable $callable
     */
    private function route(string $route, $callable)
    {
        /**
         * Closure : validate a callable 
         * @param callable|string $callable
         * @param array $arguments
         * @return callable
         */
        $getCallable = function ($callable, array $arguments = array()) {
            if (is_string($callable)) {
                if (preg_match("/([:])/", $callable)) {
                    $explode_callable = explode(':', $callable);
                    if (count($explode_callable) == 2) {
                        $className = ucwords($explode_callable[0]);
                        if (!empty($this->controller)) {
                            $className = implode('\\', [$this->controller, $className]);
                        }
                        $methodName = $explode_callable[1];
                        $classIsExist = class_exists($className) ? true : false;
                        $methodIsExist = method_exists($className, $methodName) ? true : false;
                        if (($classIsExist && $methodIsExist)) {
                            $callable = [new $className, $methodName];
                        }
                    }
                }
            }
            return function () use ($callable, $arguments) {
                return call_user_func_array($callable, $arguments);
            };
        };

        if (!empty($this->routeGroupPrefix)) {
            $route = implode('', [$this->routeGroupPrefix, $route]);
        }

        # validate the current request & route
        $currentRequestUri = filter_var($this->request->requestUri, FILTER_SANITIZE_URL);
        $currentRequestUri = rtrim($currentRequestUri, '/');
        $currentRequestUri = strtok($currentRequestUri, '?');

        # the request is http resposne code
        if (is_numeric(ltrim($currentRequestUri, '/'))) {
            $code = ltrim($currentRequestUri, '/');
            if ($this->response->getResponseMessage($code) !== null) {
                return $this->onHttpError($code);
            }
        }

        # remove slash at the end
        if ($route != '/') {
            $route = rtrim($route, '/');
        }

        # explode current request & route
        $explode_route = explode('/', $route);
        $explode_current_request_uri = explode('/', $currentRequestUri);
        array_shift($explode_route);
        array_shift($explode_current_request_uri);

        # default path '/'
        if ($explode_route[0] == '' && count($explode_current_request_uri) == 0) {
            $this->routeRequest['callable'] = $getCallable($callable, array());
        }

        # if section of route & current request are equal.
        if (count($explode_route) == count($explode_current_request_uri)) {
            $arguments = [];
            for ($i = 0; $i < count($explode_route); $i++) {
                $explode_route_part = $explode_route[$i];
                if (preg_match("/([\{$\}])/", $explode_route_part)) {
                    $explode_route_part = trim($explode_route_part, '{$}');
                    array_push($arguments, $explode_current_request_uri[$i]);
                } else if ($explode_route[$i] != $explode_current_request_uri[$i]) {
                    # if section of route & current request are equal
                    # but value of route's section & current request are not same
                    return $this;
                }
            }
            # set callable into route's request
            $this->routeRequest['callable'] = $getCallable($callable, $arguments);

            # set middleware of group into route's request
            if (!empty($this->routeGroupPrefix) && !empty($this->routeGroupMiddleware)) {
                $this->routeRequest['middleware'] = $this->routeGroupMiddleware;
            }
        }
        # return this class
        return $this;
    }
}
