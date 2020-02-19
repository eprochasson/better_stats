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
            $surveyid = Yii::app()->getRequest()->getQuery('surveyid');
            $language = Yii::app()->getRequest()->getQuery('language');
            $this->actionAction($surveyid, $language);
            $this->event->set('run', false);

        }
    }

    public function validateSurveyID($surveyId) {
        if (!isset($surveyId)) {
            $surveyId = returnGlobal('sid');
        }
        if (!$surveyId) {
            throw new CHttpException(404, 'You have to provide a valid survey ID.');
        }

        $actresult = Survey::model()->findAll('sid = :sid AND active = :active', array(':sid' => $surveyId, ':active' => 'Y')); //Checked
        if (count($actresult) == 0) {
            throw new CHttpException(404, 'You have to provide a valid survey ID.');
        }
        foreach($actresult as $act) {
            if ($act->publicstatistics != 'Y') {
                throw new CHttpException(401, "This survey does not provide public statistics");
            }
        }

        return true;
    }

    private function loadSurveyData($surveyId, $language) {
        Yii::app()->loadHelper('admin/exportresults');
        $oFormattingOptions = new FormattingOptions();
        $oFormattingOptions->responseMinRecord=1;
        $oFormattingOptions->responseMaxRecord=SurveyDynamic::model($surveyId)->getMaxId();
        $oFormattingOptions->responseCompletionState='complete';
        $oFormattingOptions->headingFormat='full';// Maybe make own to have code + abbreviated
        $oFormattingOptions->answerFormat='long';

        $surveyDao = new SurveyDao();
        $surveyInfo = $surveyDao->loadSurveyById($surveyId, $language, $oFormattingOptions);
        $surveyDao->loadSurveyResults($surveyInfo, $oFormattingOptions->responseMinRecord, $oFormattingOptions->responseMaxRecord, '', $oFormattingOptions->responseCompletionState, $oFormattingOptions->selectedColumns, $oFormattingOptions->aResponses);
        $responses = array();
        foreach($surveyInfo->responses as $response) {
            $responses []= $response;
        }

        $allQuestionsToChart = array();

        foreach ($surveyInfo->questions as $question) {
            if ($question['parent_qid'] == 0) {
                $attrs = $this->getQuestionAttributes($question, $language);
                if ($attrs['public_statistics'] == 1) {
                    $allQuestionsToChart []= array_merge(
                        $question,
                        array('attributes' => $attrs),
                        array('subquestion' => $this->loadSubQuestions($surveyInfo, $question, $responses))
                    );
                }
            }
        }
        usort($allQuestionsToChart, 'groupOrderThenQuestionOrder');
        return $allQuestionsToChart;
    }

    private function getQuestionAttributes(array $question, string $language) {
        // if it's a "subquestion", we get attributes from the parent
        if ($question['parent_qid'] != 0) {
            $qid = $question['parent_qid'];
        } else {
            $qid = $question['qid'];
        }
        $attrs = QuestionAttribute::model()->getQuestionAttributes($qid, $language);
        return array(
            'statistics_showgraph' => $attrs['statistics_showgraph'],
            'statistics_graphtype' => $attrs['statistics_graphtype'],
            'public_statistics' => $attrs['public_statistics']
        );
    }

    private function loadSubQuestions(SurveyObj $surveyInfo, array $question, array $responses) {
        $res = array();
        foreach($surveyInfo->questions as $sq) {
            if ($sq['parent_qid'] == $question['qid']) {
                $res [] = $this->loadQuestionAnswersAndResponses($surveyInfo, $sq, $responses, true);
            }
        }
        if (count($res) == 0) {
            $res []= $this->loadQuestionAnswersAndResponses($surveyInfo, $question, $responses, false);
        }

        usort($res, 'groupOrderThenQuestionOrder');
        return $res;
    }

    private function getQuestionSGQA(SurveyObj $surveyInfo, array $question, $isSubQuestion = false) {
        $fm = $surveyInfo->fieldMap;
        foreach ($fm as $field) {
            if ($isSubQuestion) {
                if (array_key_exists('sqid', $field) && $field['sqid'] == $question['qid']) {
                    $sgqa = $field['fieldname'];
                    break;
                }
            } else {
                if ($field['qid'] == $question['qid']) {
                    $sgqa = $field['fieldname'];
                    break;
                }
            }
        }
        if (!isset($sgqa)) {
            throw new Exception("I can not find this question in the fieldmap. This should not happen");
        }
        return $sgqa;
    }

    private function loadQuestionAnswersAndResponses(SurveyObj $surveyInfo, array $question, array $responses, $isSubQuestion = false) {
        $sgqa = $this->getQuestionSGQA($surveyInfo, $question, $isSubQuestion);
        $responseReturn = array();
        foreach($responses as $response) {
            if (array_key_exists($sgqa, $response) && $response[$sgqa] != '') {
                $responseReturn []= $response[$sgqa];
            }
        }
        $answers = Answer::model()->getAnswers($question['qid'])->readAll();

        return array_merge(
            $question,
            array(
                'responses' => $responseReturn,
                'answers' => $answers
            )
        );
    }

    /**
     * @param $surveyid
     * @param null $language
     * @throws CHttpException
     */
    public function actionAction($surveyId, $language = null) {
        $this->validateSurveyID($surveyId);
        $data = array();
        $data['data'] = $this->loadSurveyData($surveyId, $language);
        $data['surveyinfo'] = getSurveyInfo($surveyId, $language);

        $this->renderPartial('index', $data, false);
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
