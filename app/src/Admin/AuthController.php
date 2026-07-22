<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Auth\AuthService;
use App\Service\RateLimiter;
use App\Service\ValidationException;
use App\View\View;

final class AuthController extends AdminController
{
    // Deliberately generic (CLAUDE.md section 5): the lockout must not reveal
    // whether a username exists, so it is worded identically for every case.
    private const string LOCK_MESSAGE =
        'Zu viele Fehlversuche. Bitte warten Sie einen Moment und versuchen Sie es erneut.';

    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly RateLimiter $rateLimiter,
    ) {
        parent::__construct($view, $session);
    }

    public function showLogin(Request $request): ResponseInterface
    {
        if ($this->session->isAdmin()) {
            return Response::redirect('/admin');
        }

        return $this->render('admin/login', ['title' => 'Admin-Login', 'error' => null]);
    }

    public function login(Request $request): ResponseInterface
    {
        // Brute-force throttle (CLAUDE.md section 5): reject once too many
        // failures accumulated for this IP. The $guard already checks CSRF;
        // success/failure is only known HERE, so counting and resetting live
        // in the controller rather than in the generic guard.
        if ($this->rateLimiter->loginLocked($request->ip)) {
            return $this->render('admin/login', [
                'title' => 'Admin-Login',
                'error' => self::LOCK_MESSAGE,
            ], 429);
        }

        $username = trim((string) ($request->post['username'] ?? ''));
        $password = (string) ($request->post['password'] ?? '');

        $result = $this->auth->attempt($username, $password);

        if ($result->isAdmin) {
            $this->rateLimiter->resetLogin($request->ip);
            $this->session->loginAdmin((int) $result->adminId, (string) $result->username);

            return Response::redirect('/admin');
        }

        if ($result->isBootstrap) {
            $this->rateLimiter->resetLogin($request->ip);
            $this->session->loginBootstrap();

            return Response::redirect('/admin/setup');
        }

        $this->rateLimiter->registerLoginFailure($request->ip);

        return $this->render('admin/login', [
            'title' => 'Admin-Login',
            'error' => 'Benutzername oder Passwort ist falsch.',
        ], 401);
    }

    public function showSetup(Request $request): ResponseInterface
    {
        if (!$this->session->isBootstrap()) {
            return Response::redirect($this->session->isAdmin() ? '/admin' : '/admin/login');
        }

        return $this->render('admin/setup', [
            'title' => 'Ersten Admin anlegen',
            'errors' => [],
            'values' => [],
        ]);
    }

    public function setup(Request $request): ResponseInterface
    {
        if (!$this->session->isBootstrap()) {
            return Response::redirect($this->session->isAdmin() ? '/admin' : '/admin/login');
        }

        // Same throttle as /admin/login: while the bootstrap admin is active
        // this form is part of the auth surface, so a locked-out IP cannot
        // pivot here, and the counter is shared with the login endpoint.
        if ($this->rateLimiter->loginLocked($request->ip)) {
            return $this->render('admin/setup', [
                'title' => 'Ersten Admin anlegen',
                'errors' => ['username' => self::LOCK_MESSAGE],
                'values' => ['username' => trim((string) ($request->post['username'] ?? ''))],
            ], 429);
        }

        $username = trim((string) ($request->post['username'] ?? ''));

        try {
            $adminId = $this->auth->createFirstAdmin(
                $username,
                (string) ($request->post['password'] ?? ''),
                (string) ($request->post['password_repeat'] ?? ''),
            );
        } catch (ValidationException $e) {
            $this->rateLimiter->registerLoginFailure($request->ip);

            return $this->render('admin/setup', [
                'title' => 'Ersten Admin anlegen',
                'errors' => $e->getErrors(),
                'values' => ['username' => $username],
            ], 422);
        }

        $this->rateLimiter->resetLogin($request->ip);
        $this->session->loginAdmin($adminId, $username);
        $this->session->flash('Admin-Konto angelegt. Die Bootstrap-Zugangsdaten sind ab jetzt ungültig.');

        return Response::redirect('/admin');
    }

    public function logout(Request $request): ResponseInterface
    {
        $this->session->logout();

        return Response::redirect('/admin/login');
    }
}
