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
        $this->subscribe('beforeControllerAction');
    }

    public function beforeControllerAction()
    {
        if ($this->event->get('controller') == 'statistics_user') {
            echo '<pre>';
            $surveyid = Yii::app()->getRequest()->getQuery('surveyid');
            $language = Yii::app()->getRequest()->getQuery('language');
            $this->actionAction($surveyid, $language);
            $this->event->set('run', false);

        }
    }

    public function validateSurveyID($survey) {
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

        $this->validateSurveyID($survey);
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
        $condition = false;
        $sitename = Yii::app()->getConfig("sitename");

        $data['surveylanguage'] = $sLanguage;
        $data['sitename'] = $sitename;
        $data['condition'] = $condition;
        $data['thisSurveyCssPath'] = $thisSurveyCssPath;
        $data['thisSurveyTitle'] = $thisSurveyTitle;
        $data['totalrecords'] = $this->getTotalRecords($survey);

        Yii::app()->loadHelper('admin/exportresults');

        $oFormattingOptions = new FormattingOptions();
        $oFormattingOptions->responseMinRecord=1;
        $oFormattingOptions->responseMaxRecord=SurveyDynamic::model($iSurveyID)->getMaxId();
        $oFormattingOptions->responseCompletionState='complete';
        $oFormattingOptions->headingFormat='full';// Maybe make own to have code + abbreviated
        $oFormattingOptions->answerFormat='long';

        $surveyDao = new SurveyDao();
        $surveyInfo = $surveyDao->loadSurveyById($iSurveyID, $sLanguage, $oFormattingOptions);
        $surveyDao->loadSurveyResults($surveyInfo, $oFormattingOptions->responseMinRecord, $oFormattingOptions->responseMaxRecord, '', $oFormattingOptions->responseCompletionState, $oFormattingOptions->selectedColumns, $oFormattingOptions->aResponses);

        $responses = array();
        foreach($surveyInfo->responses as $response) {
            $responses []= $response;
        }

        $questions = $this->getPublicStatisticsQuestions($iSurveyID, $sLanguage);
        echo 'Questions<br/>';
        print_r($questions);
        echo 'fieldMap<br/>';
        print_r($surveyInfo->fieldMap);

        // Transpose the response into a table
        $per_sgqa = array();
        foreach($responses as $response) {
            foreach($response as $k => $v) {
                if ($v != '') {
                    if (array_key_exists($k, $per_sgqa)) {
                        $per_sgqa[$k] []= $v;
                    } else {
                        $per_sgqa[$k] = array($v);
                    }
                }
            }
        }

        /*
         *  We have:
         * - the list of questions (with qid) for which we need to display stats
         * - the "fieldMap", that tells sur SGQA -> qid (plus other question information
         * - all the responses, as SGQA -> data
         *
         * What we want:
         * For each question to describe, the list of answers (can have more than one).
         * Some questions have several subquestions, we need to deal with that.
         */

        $questionMapped = array();
        foreach($questions as $question) {
            $question['answers'] = array();
            foreach($surveyInfo->fieldMap as $map) {
                if ($map['qid'] == $question['qid']) {
                    $question['answers'][] = array_merge(
                        $map,
                        array('values' => $per_sgqa[$map['fieldname']])
                    );
                }
            }
            $questionMapped[] = $question;
        }
        print_r($questionMapped);


        if ($surveyInfo->responses instanceof CDbDataReader) {
            $surveyInfo->responses->close();
        }
    }

    public function getPublicStatisticsQuestions($surveyId, $sLanguage) {
        $query = "SELECT q.* , group_name, group_order FROM {{questions}} q, {{groups}} g, {{question_attributes}} qa
                    WHERE g.gid = q.gid AND g.language = :lang1 AND q.language = :lang2 AND q.sid = :surveyid AND q.qid = qa.qid AND q.parent_qid = 0 AND qa.attribute = 'public_statistics'";
        $databasetype = Yii::app()->db->getDriverName();
        if ($databasetype == 'mssql' || $databasetype == "sqlsrv" || $databasetype == "dblib") {
            $query .= " AND CAST(CAST(qa.value as varchar) as int)='1'\n";
        } else {
            $query .= " AND qa.value='1'\n";
        }
        $result = Yii::app()->db->createCommand($query)->bindParam(":lang1", $sLanguage, PDO::PARAM_STR)->bindParam(":lang2", $sLanguage, PDO::PARAM_STR)->bindParam(":surveyid", $surveyId, PDO::PARAM_INT)->queryAll();
        $rows = $result;
        //SORT IN NATURAL ORDER!
        usort($rows, 'groupOrderThenQuestionOrder');

        $res = array();
        foreach ($rows as $row) {
            $res[] = array(
                'qid' => $row['qid'],
                'gid' => $row['gid'],
                'type' => $row['type'],
                'title' => $row['title'],
                'group_name' => $row['group_name'],
                'question' => flattenText($row['question'])
            );
        }
        return $res;
    }

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
