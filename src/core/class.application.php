<?php

namespace leantime\core;

use leantime\domain\services;
use leantime\domain\repositories;

class application
{

    private $config;
    private $settings;
    private $login;
    private $frontController;
    private $language;
    private $projectService;
    private $settingsRepo;
    private $reportService;

    private $publicActions = array(
        "auth.login",
        "auth.resetPw",
        "install",
        "install.update",
        "general.error404",
        "api.i18n"
    );


    public function __construct()
    {

        //Set Session
        $session = session::getInstance();
        $this->auth = services\auth::getInstance($session->getSID());

        $this->config = new config();
        $this->settings = new appSettings();

        $this->frontController = frontcontroller::getInstance(ROOT);
        $this->language = new language();
        $this->projectService = new services\projects();
        $this->settingsRepo = new repositories\setting();
        $this->reportService = new services\reports();





    }

    /**
     * start - renders application and routes to correct template, writes content to output buffer
     *
     * @access public static
     * @return void
     */
    public function start()
    {
        //Only run telemetry when logged in
        $telemetryResponse = false;

        $this->loadHeaders();

        //Override theme settings
        $this->overrideThemeSettings();

        //Check if Leantime is installed
        $this->checkIfInstalled();

        //Allow a limited set of actions to be public


        if($this->auth->logged_in()===true) {

            //Send telemetry if user is opt in and if it hasn't been sent that day
            $telemetryResponse = $this->reportService->sendAnonymousTelemetry();

            // Check if trying to access twoFA code page, or if trying to access any other action without verifying the code.
            if($_SESSION['userdata']['twoFAEnabled'] && $_SESSION['userdata']['twoFAVerified'] === false){

                if($this->frontController::getCurrentRoute() !== "twoFA.verify"
                    && $this->frontController::getCurrentRoute() !== "auth.logout"
                    && $this->frontController::getCurrentRoute() !== "api.i18n") {
                    $this->frontController::redirect(BASE_URL."/twoFA/verify");
                }

            }else{

                //House keeping when logged in.
                //Set current/default project
                $this->projectService->setCurrentProject();

            }



        }else{


            if(!in_array(frontController::getCurrentRoute(), $this->publicActions)) {

                if ($this->frontController::getCurrentRoute() !== "auth.login") {
                    $this->frontController::redirect(BASE_URL . "/auth/login");
                }

            }

        }

        //Dispatch controller
        $this->frontController::dispatch();

        //Wait for telemetry if it was sent
        if($telemetryResponse !== false){

            try {

                $telemetryResponse->wait();

            }catch(\Exception $e){

                error_log($e->getMessage(), 0);

            }

        }
            
    }

    private function loadHeaders() {

        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('X-Content-Type-Options: nosniff');

    }

    private function overrideThemeSettings()
    {
        if (isset($_SESSION["companysettings.logoPath"]) === false) {
            $logoPath = $this->settingsRepo->getSetting("companysettings.logoPath");

            if ($logoPath !== false) {
                if (strpos($logoPath, 'http') === 0) {
                    $_SESSION["companysettings.logoPath"] = $logoPath;
                } else {
                    $_SESSION["companysettings.logoPath"] = BASE_URL . $logoPath;
                }
            } else {
                if (strpos($this->config->logoPath, 'http') === 0) {
                    $_SESSION["companysettings.logoPath"] = $this->config->logoPath;
                } else {
                    $_SESSION["companysettings.logoPath"] = BASE_URL . $this->config->logoPath;
                }
            }
        }

        if (isset($_SESSION["companysettings.primarycolor"]) === false) {
            $_SESSION["companysettings.primarycolor"] = "#1b75bb";
            $_SESSION["companysettings.secondarycolor"] = "#81B1A8";

            //Old setting
            $mainColor = $this->settingsRepo->getSetting("companysettings.mainColor");
            if ($mainColor !== false) {
                $_SESSION["companysettings.primarycolor"] = "#" . $mainColor;
                $_SESSION["companysettings.secondarycolor"] = "#" . $mainColor;
            }

            //new setting
            $primaryColor = $this->settingsRepo->getSetting("companysettings.primarycolor");
            if ($primaryColor !== false) {
                $_SESSION["companysettings.primarycolor"] = $primaryColor;
                $_SESSION["companysettings.secondarycolor"] = $primaryColor;
            }

            $secondaryColor = $this->settingsRepo->getSetting("companysettings.secondarycolor");
            if ($secondaryColor !== false) {
                $_SESSION["companysettings.secondarycolor"] = $secondaryColor;
            }
        } else {
            if (!str_starts_with($_SESSION["companysettings.primarycolor"], "#")) {
                $_SESSION["companysettings.primarycolor"] = "#" . $_SESSION["companysettings.primarycolor"];
                $_SESSION["companysettings.secondarycolor"] = "#" . $_SESSION["companysettings.primarycolor"];
            }
        }

        if (isset($_SESSION["companysettings.sitename"]) === false) {
            $sitename = $this->settingsRepo->getSetting("companysettings.sitename");
            if ($sitename !== false) {
                $_SESSION["companysettings.sitename"] = $sitename;
            } else {
                $_SESSION["companysettings.sitename"] = $this->config->sitename;
            }
        }

        if (isset($_SESSION["companysettings.language"]) === false || $_SESSION["companysettings.language"] == false) {
            $language = $this->settingsRepo->getSetting("companysettings.language");
            if ($language !== false) {
                $_SESSION["companysettings.language"] = $language;
            } else {
                $_SESSION["companysettings.language"] = $this->config->language;
            }
        }
    }

    private function checkIfInstalled() {

        if(isset($_SESSION['isInstalled']) && $_SESSION['isInstalled'] === true) {
            return;
        }
        //Only run this if the user is not logged in (db should be updated/installed before user login)
        if($this->auth->logged_in()===false) {

            if(!isset($_SESSION['isInstalled']) || $_SESSION['isInstalled'] === false) {
                if ($this->settingsRepo->checkIfInstalled() === false && isset($_GET['install']) === false) {

                    //Don't redirect on i18n call
                    if($this->frontController::getCurrentRoute() !== "install" &&
                        $this->frontController::getCurrentRoute() !== "api.i18n") {
                        $this->frontController::redirect(BASE_URL . "/install");
                    }

                }else{
                    $_SESSION['isInstalled'] = true;
                }
            }


            if(isset($_SESSION['isInstalled']) && $_SESSION['isInstalled'] === true && (!isset($_SESSION['isUpToDate']) || $_SESSION['isUpToDate'] === false)) {
                $dbVersion = $this->settingsRepo->getSetting("db-version");
                if ($this->settings->dbVersion != $dbVersion && isset($_GET['update']) === false && isset($_GET['install']) === false) {

                    //Don't redirect on i18n call
                        if($this->frontController::getCurrentRoute() !== "install.update" &&
                            $this->frontController::getCurrentRoute() !== "api.i18n"){
                            $this->frontController::redirect(BASE_URL . "/install/update");
                        }


                }else {
                    $_SESSION['isUpToDate'] = true;
                }
            }
        }
    }
}
