<?php

/**
 * betterStats
 * @version 1.1.1
 *
 * @category Plugin
 */


class betterStats extends PluginBase
{

    protected $storage = 'DbStorage';
    static protected $description = 'Show better stats on survey completion';
    static protected $name = 'BetterStats';
    protected $surveyId;

    protected $settings = array(
        'logo' => array(
            'type' => 'logo',
            'path' => 'assets/logo.png'
        ),

        'message' => array(
            'type' => 'string',
            'label' => 'Message'
        ),

    );

    public function init()
    {
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('newDirectRequest');
//        $this->subscribe('beforeControllerAction', 'replacePublicStats');
    }


    public function replacePublicStats()
    {
        if ($this->event->get('controller') == 'statistics_user') {
            $surveyid = Yii::app()->getRequest()->getQuery('surveyid');
            $language = Yii::app()->getRequest()->getQuery('language');
            $this->actionAction($surveyid, $language);
            $this->event->set('run', false);
        }
    }

    public function validateSurvey($survey) {
        $iSurveyID = (int) $survey->sid;
        $this->iSurveyID = $survey->sid;

        if (!isset($iSurveyID)) {
            $iSurveyID = returnGlobal('sid');
        } else {
            $iSurveyID = (int) $iSurveyID;
        }
        if (!$iSurveyID) {
            throw new CHttpException(404, 'You have to provide a valid survey ID.');
        }

        $actresult = Survey::model()->findAll('sid = :sid AND active = :active', array(':sid' => $iSurveyID, ':active' => 'Y')); //Checked
        if (count($actresult) == 0) {
            throw new CHttpException(404, 'You have to provide a valid survey ID.');
        }

        return true;
    }

    /**
     * This is a copy/paste from `application/controllers/Statistics_userController.php`
     * @param $surveyid
     * @param null $language
     * @throws CHttpException
     */
    public function actionAction($surveyid, $language = null)
    {
        $sLanguage = $language;
        $survey = Survey::model()->findByPk($surveyid);

        $this->sLanguage = $language;


        Yii::app()->loadHelper('database');
        Yii::app()->loadHelper('surveytranslator');
        // The stuff we'll pass to the templating engine in the end.
        $data = array();

        $this->validateSurvey($survey);
        $iSurveyID = (int) $survey->sid;
        $this->iSurveyID = $survey->sid;

        $surveyinfo = getSurveyInfo($iSurveyID);
        $thisSurveyTitle = $surveyinfo["name"];
        $thisSurveyCssPath = getTemplateURL($surveyinfo["template"]);
        if ($surveyinfo['publicstatistics'] != 'Y') {
            throw new CHttpException(401, 'The public statistics for this survey are deactivated.');
        }

        //check if graphs should be shown for this survey
        if ($survey->isPublicGraphs) {
            $publicgraphs = 1;
        } else {
            $publicgraphs = 0;
        }

        // Set language for questions and labels to base language of this survey
        if ($sLanguage == null || !in_array($sLanguage, Survey::model()->findByPk($iSurveyID)->getAllLanguages())) {
            $sLanguage = Survey::model()->findByPk($iSurveyID)->language;
        } else {
            $sLanguage = sanitize_languagecode($sLanguage);
        }
        //set survey language for translations
        SetSurveyLanguage($iSurveyID, $sLanguage);
        //Create header
        $condition = false;
        $sitename = Yii::app()->getConfig("sitename");

        $data['surveylanguage'] = $sLanguage;
        $data['sitename'] = $sitename;
        $data['condition'] = $condition;
        $data['thisSurveyCssPath'] = $thisSurveyCssPath;

        $data['sgqa'] = $this->getSGQAData($iSurveyID, $sLanguage);
        $data['thisSurveyTitle'] = $thisSurveyTitle;
        $data['totalrecords'] = $this->getTotalRecords($survey);

        Yii::app()->getClientScript()->registerScriptFile(Yii::app()->getConfig('generalscripts').'statistics_user.js');
        $this->layout = "public";
        echo '<pre>' ; print_r($data);
    }

    public function getSGQAData($iSurveyID, $sLanguage) {
        $res = array();
        $data = $this->getQuestionsFromDatabase($iSurveyID, $sLanguage);
        foreach($data as $sgq) {
            $res[] = $this->getSGQAnswers($sgq);
        }
        return $res;
    }

    private function runQuestionQuery($query, $qid, $sLanguage) {
        return Yii::app()->db->createCommand($query)->bindParam(":qid", $qid, PDO::PARAM_INT)->bindParam(":lang", $sLanguage, PDO::PARAM_STR)->queryAll();
    }

    /**
     * This is largely copied from `application/controllers/Statistics_userController.php::createSGQA`
     * @param $sgq
     * @throws CException
     */
    public function getSGQAnswers($sgq) {
        $thisfield = $this->iSurveyID.'X'.$sgq['gid'].'X'.$sgq['sid'];
        $type = $sgq['type'];
        $qid = $sgq['qid'];
        $gid = $sgq['gid'];
        $lang = $sgq['language'];
        $res = [];
        switch ($type) {
            case "X":  //This is a boilerplate question and it has no business in this script
                break;
            case "N":  //N - Numerical input
                $result = $this->runQuestionQuery("SELECT title as code, question as answer FROM {{questions}} WHERE parent_qid=:qid AND language = :lang ORDER BY question_order", $sgq['sid'], $sgq['language']);
                echo '<pre>';
                print_r($result);
                foreach ($result as $row) {
                    $res[] = $type.$thisfield.reset($row);
                }

//                $res[] = array_merge($sgq, array('values' => $sgq['type'].$thisfield));
                break;
            case "P":  //P - Multiple choice with comments
            case "M":  //M - Multiple choice
            case "D":  //D - Date
            case "K": // Multiple Numerical
            case "Q": // Multiple Short Text
            case "A": // ARRAY OF 5 POINT CHOICE QUESTIONS
            case "B": // ARRAY OF 10 POINT CHOICE QUESTIONS
            case "C": // ARRAY OF YES\No\gT("Uncertain") QUESTIONS
            case "E": // ARRAY OF Increase/Same/Decrease QUESTIONS
            case "F": // FlEXIBLE ARRAY
            case "H": // ARRAY (By Column)
            case "T": // Long free text
            case "U": // Huge free text
            case "S": // Short free text
            case ";":  //ARRAY (Multi Flex) (Text)
            case ":":  //ARRAY (Multi Flex) (Numbers)
            case "R": //RANKING
            case "1": // MULTI SCALE
            default:   //Default settings
                $res[] = array('error' => 'Unsupported question type '.$type);
                $res[] = array_merge($sgq, array('values' => $thisfield));
                break;
        }

        return $res;
    }

    public function getQuestionsFromDatabase($iSurveyID, $sLanguage) {
        $query = "SELECT q.*, group_name, group_order 
                  FROM {{questions}} q, {{groups}} g, {{question_attributes}} qa
                  WHERE g.gid = q.gid AND g.language = :lang1 AND q.language = :lang2 AND q.sid = :surveyid 
                  AND q.qid = qa.qid AND q.parent_qid = 0 AND qa.attribute = 'public_statistics'";
        $databasetype = Yii::app()->db->getDriverName();
        if ($databasetype == 'mssql' || $databasetype == "sqlsrv" || $databasetype == "dblib") {
            $query .= " AND CAST(CAST(qa.value as varchar) as int)='1'\n";
        } else {
            $query .= " AND qa.value='1'\n";
        }

        $result = Yii::app()->db->createCommand($query)->bindParam(":lang1", $sLanguage, PDO::PARAM_STR)->bindParam(":lang2", $sLanguage, PDO::PARAM_STR)->bindParam(":surveyid", $iSurveyID, PDO::PARAM_INT)->queryAll();

        $rows = $result;
        usort($rows, 'groupOrderThenQuestionOrder');
        return $rows;
    }

//    public function createSGQA($filters) {
//        $allfields = array();
//
//        foreach ($filters as $flt) {
//            //SGQ identifier
//            $myfield = $this->iSurveyID.'X'.$flt[1].'X'.$flt[0];
//
//            //let's switch through the question type for each question
//            switch ($flt[2]) {
//                case "K": // Multiple Numerical
//                case "Q": // Multiple Short Text
//                    //get answers
//                    $query = "SELECT title as code, question as answer FROM {{questions}} WHERE parent_qid=:flt_0 AND language = :lang ORDER BY question_order";
//                    $result = Yii::app()->db->createCommand($query)->bindParam(":flt_0", $flt[0], PDO::PARAM_INT)->bindParam(":lang", $this->sLanguage, PDO::PARAM_STR)->queryAll();
//
//                    //go through all the (multiple) answers
//                    foreach ($result as $row) {
//                        $myfield2 = $flt[2].$myfield.reset($row);
//                        $allfields[] = $myfield2;
//                    }
//                    break;
//                case "A": // ARRAY OF 5 POINT CHOICE QUESTIONS
//                case "B": // ARRAY OF 10 POINT CHOICE QUESTIONS
//                case "C": // ARRAY OF YES\No\gT("Uncertain") QUESTIONS
//                case "E": // ARRAY OF Increase/Same/Decrease QUESTIONS
//                case "F": // FlEXIBLE ARRAY
//                case "H": // ARRAY (By Column)
//                    //get answers
//                    $query = "SELECT title as code, question as answer FROM {{questions}} WHERE parent_qid=:flt_0 AND language = :lang ORDER BY question_order";
//                    $result = Yii::app()->db->createCommand($query)->bindParam(":flt_0", $flt[0], PDO::PARAM_INT)->bindParam(":lang", $this->sLanguage, PDO::PARAM_STR)->queryAll();
//
//                    //go through all the (multiple) answers
//                    foreach ($result as $row) {
//                        $myfield2 = $myfield.reset($row);
//                        $allfields[] = $myfield2;
//                    }
//                    break;
//                // all "free text" types (T, U, S)  get the same prefix ("T")
//                case "T": // Long free text
//                case "U": // Huge free text
//                case "S": // Short free text
//                    $myfield = "T".$myfield;
//                    $allfields[] = $myfield;
//                    break;
//                case ";":  //ARRAY (Multi Flex) (Text)
//                case ":":  //ARRAY (Multi Flex) (Numbers)
//                    $query = "SELECT title, question FROM {{questions}} WHERE parent_qid=:flt_0 AND language=:lang AND scale_id = 0 ORDER BY question_order";
//                    $result = Yii::app()->db->createCommand($query)->bindParam(":flt_0", $flt[0], PDO::PARAM_INT)->bindParam(":lang", $this->sLanguage, PDO::PARAM_STR)->queryAll();
//                    foreach ($result as $row) {
//                        $fquery = "SELECT * FROM {{questions}} WHERE parent_qid = :flt_0 AND language = :lang AND scale_id = 1 ORDER BY question_order, title";
//                        $fresult = Yii::app()->db->createCommand($fquery)->bindParam(":flt_0", $flt[0], PDO::PARAM_INT)->bindParam(":lang", $this->sLanguage, PDO::PARAM_STR)->queryAll();
//                        foreach ($fresult as $frow) {
//                            $myfield2 = $myfield.reset($row)."_".$frow['title'];
//                        $allfields[] = $myfield2;
//                    }
//                    }
//                    break;
//                case "R": //RANKING
//                    //get some answers
//                    $query = "SELECT code, answer FROM {{answers}} WHERE qid = :flt_0 AND language = :lang ORDER BY sortorder, answer";
//                    $result = Yii::app()->db->createCommand($query)->bindParam(":flt_0", $flt[0], PDO::PARAM_INT)->bindParam(":lang", $this->sLanguage, PDO::PARAM_STR)->queryAll();
//
//                    //get number of answers
//                    $count = count($result);
//
//                    //loop through all answers. if there are 3 items to rate there will be 3 statistics
//                    for ($i = 1; $i <= $count; $i++) {
//                        $myfield2 = "R".$myfield.$i."-".strlen($i);
//                        $allfields[] = $myfield2;
//                    }
//                    break;
//                //Boilerplate questions are only used to put some text between other questions -> no analysis needed
//                case "X":  //This is a boilerplate question and it has no business in this script
//                    break;
//                case "1": // MULTI SCALE
//                    //get answers
//                    $query = "SELECT title, question FROM {{questions}} WHERE parent_qid = :flt_0 AND language = :lang ORDER BY question_order";
//                    $result = Yii::app()->db->createCommand($query)->bindParam(":flt_0", $flt[0], PDO::PARAM_INT)->bindParam(":lang", $this->sLanguage, PDO::PARAM_STR)->queryAll();
//
//                    //loop through answers
//                    foreach ($result as $row) {
//                        //----------------- LABEL 1 ---------------------
//                        $myfield2 = $myfield.$row['title']."#0";
//                        $allfields[] = $myfield2;
//                        //----------------- LABEL 2 ---------------------
//                        $myfield2 = $myfield.$row['title']."#1";
//                        $allfields[] = $myfield2;
//                    }    //end WHILE -> loop through all answers
//                    break;
//
//                case "P":  //P - Multiple choice with comments
//                case "M":  //M - Multiple choice
//                case "N":  //N - Numerical input
//                case "D":  //D - Date
//                    $myfield2 = $flt[2].$myfield;
//                    $allfields[] = $myfield2;
//                    break;
//                default:   //Default settings
//                    $allfields[] = $myfield;
//                    break;
//
//            }    //end switch -> check question types and create filter forms
//        }
//
//        return $allfields;
//    }

    public function getTotalRecords($survey) {
        //count number of answers
        $query = "SELECT count(*) FROM ".$survey->responsesTableName;

        //if incompleted answers should be filter submitdate has to be not null
        //this setting is taken from config-defaults.php
        if (Yii::app()->getConfig("filterout_incomplete_answers") == true) {
            $query .= " WHERE ".$survey->responsesTableName.".submitdate is not null";
        }
        $result = Yii::app()->db->createCommand($query)->queryAll();

        //$totalrecords = total number of answers
        foreach ($result as $row) {
            $totalrecords = reset($row);
        }

        return $totalrecords;
    }


}
