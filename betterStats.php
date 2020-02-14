<?php

/**
 * betterStats
 * @version 1.1.1
 *
 * @category Plugin
 */


class betterStats extends PluginBase {

    protected $settings = array(
        'logo' => array(
            'type' => 'logo',
            'path' => 'assets/logo.png'
        ),

        'message' => array(
            'type' => 'string',
            'label' => 'Message'
        )
    );
    public function onPluginRegistration(){
        $pluginName = $this->get('pluginName');
        //Maybe add a check for the correct pluginName here
        //Check if the menu/menu entries are already created use yii database methods for that

        $menuArray = array(
            "parent_id" => 1, //1 -> main surveymenu, 2-> quickemenu, NULL -> new base menu in sidebar
            "name" => "[your plugin menus name]",
            "title" => "[your plugin menus title]",
            "position" => "side", // possible positions are "side" and "collapsed" state 3.0.0.beta-2
            "description" => "[your plugins menu description]"
        );
        $newMenuId = Surveymenu::staticAddMenu($menuArray);

        // repeat this as often as you need it
        $menuEntryArray = array(
            "name" => "[name of the action]",
            "title" => "[title for the action]",
            "menu_title" => "[title for the action]",
            "menu_description" => "[description for the action]",
            "menu_icon" => "[icon for action]", //it is either the fontawesome classname withot the fa- prefix, or the iconclass classname, or a src link
            "menu_icon_type" => "fontawesome", // either 'fontawesome', 'iconclass' or 'image'
            "menu_link" => "[admin/]controller/sa/action", //the link will be parsed through yii's createURL method
            "addSurveyId" => true, //add the surveyid parameter to the url
            "addQuestionGroupId" => true, //add gid parameter to url
            "addQuestionId" => true, //add qid parameter to url
            "linkExternal" => false, //open link in a new tab/window
            "hideOnSurveyState" => null, //possible values are "active", "inactive" and null
            "manualParams" => "" //please read up on this setting as it may render the link useless
        );
        SurveymenuEntries::staticAddMenuEntry($newMenuId, $menuEntryArray);

    }
}
