<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\SurveyBundle\Controller;

use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Library\Resource\ResourceCollection;
use Claroline\SurveyBundle\Entity\Answer\MultipleChoiceQuestionAnswer;
use Claroline\SurveyBundle\Entity\Answer\OpenEndedQuestionAnswer;
use Claroline\SurveyBundle\Entity\Answer\QuestionAnswer;
use Claroline\SurveyBundle\Entity\Answer\SurveyAnswer;
use Claroline\SurveyBundle\Entity\Question;
use Claroline\SurveyBundle\Entity\Survey;
use Claroline\SurveyBundle\Entity\SurveyQuestionRelation;
use Claroline\SurveyBundle\Form\QuestionType;
use Claroline\SurveyBundle\Form\SurveyEditionType;
use Claroline\SurveyBundle\Manager\SurveyManager;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\SecurityContextInterface;

class SurveyController extends Controller
{
    private $formFactory;
    private $request;
    private $router;
    private $security;
    private $surveyManager;
    private $templating;

    /**
     * @DI\InjectParams({
     *     "formFactory"   = @DI\Inject("form.factory"),
     *     "requestStack"  = @DI\Inject("request_stack"),
     *     "router"        = @DI\Inject("router"),
     *     "security"      = @DI\Inject("security.context"),
     *     "surveyManager" = @DI\Inject("claroline.manager.survey_manager"),
     *     "templating"    = @DI\Inject("templating")
     * })
     */
    public function __construct(
        FormFactory $formFactory,
        RequestStack $requestStack,
        UrlGeneratorInterface $router,
        SecurityContextInterface $security,
        SurveyManager $surveyManager,
        TwigEngine $templating
    )
    {
        $this->formFactory = $formFactory;
        $this->request = $requestStack;
        $this->router = $router;
        $this->security = $security;
        $this->surveyManager = $surveyManager;
        $this->templating = $templating;
    }

    /**
     * @EXT\Route(
     *     "/{survey}",
     *     name="claro_survey_index"
     * )
     * @EXT\ParamConverter("user", options={"authenticatedUser" = true})
     * @EXT\Template()
     *
     * @param Survey $survey
     * @return array
     */
    public function indexAction(Survey $survey, User $user)
    {
        $this->checkSurveyRight($survey, 'OPEN');
        $canEdit = $this->hasSurveyRight($survey, 'EDIT');
        $this->surveyManager->updateSurveyStatus($survey);
        $status = $this->computeStatus($survey);
        $surveyAnswer = $this->surveyManager
            ->getSurveyAnswerBySurveyAndUser($survey, $user);
        $hasAnswered = !is_null($surveyAnswer);

        if ($canEdit) {

            return new Response(
                $this->templating->render(
                    "ClarolineSurveyBundle:Survey:surveyEditionMainMenu.html.twig",
                    array(
                        'survey' => $survey,
                        'status' => $status
                    )
                )
            );
        }
        $currentDate = new \DateTime();

        return array(
            'survey' => $survey,
            'status' => $status,
            'currentDate' => $currentDate,
            'hasAnswered' => $hasAnswered
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/parameters/edit/form",
     *     name="claro_survey_parameters_edit_form"
     * )
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyParametersEditFormAction(Survey $survey)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $form = $this->formFactory->create(
            new SurveyEditionType(),
            $survey
        );

        return array(
            'form' => $form->createView(),
            'survey' => $survey
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/parameters/edit",
     *     name="claro_survey_parameters_edit"
     * )
     * @EXT\Template( "ClarolineSurveyBundle:Survey:surveyParametersEditForm.html.twig")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyParametersEditAction(Survey $survey)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $form = $this->formFactory->create(
            new SurveyEditionType(),
            $survey
        );
        $form->handleRequest($this->request->getCurrentRequest());

        if ($form->isValid()) {
            $this->surveyManager->persistSurvey($survey);

            return new RedirectResponse(
                $this->router->generate(
                    'claro_survey_index',
                    array('survey' => $survey->getId())
                )
            );
        }

        return array(
            'form' => $form->createView(),
            'survey' => $survey
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/management",
     *     name="claro_survey_management"
     * )
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyManagementAction(Survey $survey)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $questionRelations = $survey->getQuestionRelations();

        return array(
            'survey' => $survey,
            'questionRelations' => $questionRelations
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/display",
     *     name="claro_survey_display",
     *     options={"expose"=true}
     * )
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyDisplayAction(Survey $survey)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $questionViews = array();

        foreach ($survey->getQuestionRelations() as $relation) {
            $question = $relation->getQuestion();
            $questionViews[] =
                $this->typedQuestionDisplayAction($survey, $question)->getContent();
        }

        return array(
            'survey' => $survey,
            'questionRelations' => $survey->getQuestionRelations(),
            'questionViews' => $questionViews
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/publish",
     *     name="claro_survey_publish"
     * )
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyPublishAction(Survey $survey)
    {
        $this->checkSurveyRight($survey, 'EDIT');

        if (!$survey->isPublished() || $survey->isClosed()) {
            $survey->setClosed(false);
            $survey->setPublished(true);
            $this->surveyManager->persistSurvey($survey);
        }

        return new RedirectResponse(
            $this->router->generate(
                'claro_survey_index',
                array('survey' => $survey->getId())
            )
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/close",
     *     name="claro_survey_close"
     * )
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyCloseAction(Survey $survey)
    {
        $this->checkSurveyRight($survey, 'EDIT');

        if (!$survey->isClosed()) {
            $survey->setClosed(true);
            $this->surveyManager->persistSurvey($survey);
        }

        return new RedirectResponse(
            $this->router->generate(
                'claro_survey_index',
                array('survey' => $survey->getId())
            )
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/questions/management/ordered/by/{orderedBy}/order/{order}",
     *     name="claro_survey_questions_management",
     *     defaults={"ordered"="title","order"="ASC"}
     * )
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function questionsManagementAction(Survey $survey, $orderedBy, $order)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $questions = $this->surveyManager->getQuestionsByWorkspace(
            $survey->getResourceNode()->getWorkspace(),
            $orderedBy,
            $order
        );

        return array(
            'survey' => $survey,
            'questions' => $questions,
            'orderedBy' => $orderedBy,
            'order' => $order
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/questions/list",
     *     name="claro_survey_questions_list",
     *     options={"expose"=true}
     * )
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function questionsListAction(Survey $survey)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $relations = $survey->getQuestionRelations();
        $exclusions = array();

        foreach ($relations as $relation) {
            $exclusions[] = $relation->getQuestion()->getId();
        }

        $questions = $this->surveyManager->getAvailableQuestions(
            $survey->getResourceNode()->getWorkspace(),
            $exclusions,
            'title',
            'ASC'
        );

        return array(
            'survey' => $survey,
            'questions' => $questions
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/question/create/form/source/{source}",
     *     name="claro_survey_question_create_form",
     *     defaults={"source"="question"}
     * )
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function questionCreateFormAction(Survey $survey, $source)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $form = $this->formFactory->create(
            new QuestionType(),
            new Question()
        );

        return array(
            'form' => $form->createView(),
            'survey' => $survey,
            'source' => $source
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/question/create/source/{source}",
     *     name="claro_survey_question_create",
     *     defaults={"source"="question"}
     * )
     * @EXT\Template("ClarolineSurveyBundle:Survey:questionCreateForm.html.twig")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function questionCreateAction(Survey $survey, $source)
    {
        $this->checkSurveyRight($survey, 'EDIT');
        $question  = new Question();
        $form = $this->formFactory->create(
            new QuestionType(),
            $question
        );
        $form->handleRequest($this->request->getCurrentRequest());

        if ($form->isValid()) {
            $question->setWorkspace($survey->getResourceNode()->getWorkspace());
            $this->surveyManager->persistQuestion($question);
            $questionType = $question->getType();

            switch ($questionType) {

                case 'multiple_choice_single':
                case 'multiple_choice_multiple':
                    $postDatas = $this->request->getCurrentRequest()->request->all();
                    $this->updateMultipleChoiceQuestion($question, $postDatas);
                    break;
                case 'open-ended':
                default:
                    break;
            }

            if ($source === 'survey') {
                $this->surveyManager
                    ->createSurveyQuestionRelation($survey, $question);

                return new RedirectResponse(
                    $this->router->generate(
                        'claro_survey_management',
                        array('survey' => $survey->getId())
                    )
                );
            } else {

                return new RedirectResponse(
                    $this->router->generate(
                        'claro_survey_questions_management',
                        array(
                            'survey' => $survey->getId(),
                            'orderedBy' => 'title',
                            'order' => 'ASC'
                        )
                    )
                );
            }
        }

        return array(
            'form' => $form->createView(),
            'survey' => $survey,
            'source' => $source
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/question/{question}/edit/form/source/{source}",
     *     name="claro_survey_question_edit_form",
     *     defaults={"source"="question"}
     * )
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function questionEditFormAction(
        Question $question,
        Survey $survey,
        $source
    )
    {
        $this->checkQuestionRight($survey, $question, 'EDIT');
        $form = $this->formFactory->create(
            new QuestionType(),
            $question
        );

        return array(
            'form' => $form->createView(),
            'question' => $question,
            'survey' => $survey,
            'source' => $source
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/question/{question}/edit/source/{source}",
     *     name="claro_survey_question_edit",
     *     defaults={"source"="question"}
     * )
     * @EXT\Template("ClarolineSurveyBundle:Survey:questionEditForm.html.twig")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function questionEditAction(
        Question $question,
        Survey $survey,
        $source
    )
    {
        $this->checkQuestionRight($survey, $question, 'EDIT');
        $form = $this->formFactory->create(
            new QuestionType(),
            $question
        );
        $form->handleRequest($this->request->getCurrentRequest());

        if ($form->isValid()) {
            $question->setWorkspace($survey->getResourceNode()->getWorkspace());
            $this->surveyManager->persistQuestion($question);
            $questionType = $question->getType();

            switch ($questionType) {

                case 'multiple_choice_single':
                case 'multiple_choice_multiple':
                    $postDatas = $this->request->getCurrentRequest()->request->all();
                    $this->updateMultipleChoiceQuestion($question, $postDatas);
                    break;
                case 'open-ended':
                default:
                    break;
            }

            if ($source === 'survey') {

                return new RedirectResponse(
                    $this->router->generate(
                        'claro_survey_management',
                        array('survey' => $survey->getId())
                    )
                );
            } else {

                return new RedirectResponse(
                    $this->router->generate(
                        'claro_survey_questions_management',
                        array(
                            'survey' => $survey->getId(),
                            'orderedBy' => 'title',
                            'order' => 'ASC'
                        )
                    )
                );
                }
        }

        return array(
            'form' => $form->createView(),
            'question' => $question,
            'survey' => $survey,
            'source' => $source
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/question/{question}/delete",
     *     name="claro_survey_question_delete",
     *     options={"expose"=true}
     * )
     * @EXT\Template("ClarolineSurveyBundle:Survey:questionsManagement.html.twig")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function questionDeleteAction(Question $question, Survey $survey)
    {
        $this->checkQuestionRight($survey, $question, 'EDIT');
        $this->surveyManager->deleteQuestion($question);

        return new RedirectResponse(
            $this->router->generate(
                'claro_survey_questions_management',
                array(
                    'survey' => $survey->getId(),
                    'orderedBy' => 'title',
                    'order' => 'ASC'
                )
            )
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/add/question/{question}",
     *     name="claro_survey_add_question",
     *     options={"expose"=true}
     * )
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyAddQuestionAction(Survey $survey, Question $question)
    {
        $this->checkQuestionRight($survey, $question, 'EDIT');
        $this->surveyManager->createSurveyQuestionRelation($survey, $question);

        return new RedirectResponse(
            $this->router->generate(
                'claro_survey_management',
                array('survey' => $survey->getId())
            )
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/remove/question/{question}",
     *     name="claro_survey_remove_question",
     *     options={"expose"=true}
     * )
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyRemoveQuestionAction(Survey $survey, Question $question)
    {
        $this->checkQuestionRight($survey, $question, 'EDIT');
        $this->surveyManager->deleteSurveyQuestionRelation($survey, $question);

        return new RedirectResponse(
            $this->router->generate(
                'claro_survey_management',
                array('survey' => $survey->getId())
            )
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/typed/question/{question}/display",
     *     name="claro_survey_typed_question_display",
     *     options={"expose"=true}
     * )
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function typedQuestionDisplayAction(Survey $survey, Question $question)
    {
        $this->checkQuestionRight($survey, $question, 'OPEN');
        $questionType = $question->getType();

        switch ($questionType) {

            case 'multiple_choice_single' :
            case 'multiple_choice_multiple' :

                return $this->displayMultipleChoiceQuestion($question);
            case 'open_ended':

                return $this->displayOpenEndedQuestion($question);
            default:
                break;
        }

        return new Response();
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/type/{questionType}/create/form",
     *     name="claro_survey_typed_question_create_form",
     *     options={"expose"=true}
     * )
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function typedQuestionCreateFormAction(
        Survey $survey,
        $questionType
    )
    {
        $this->checkSurveyRight($survey, 'EDIT');

        switch ($questionType) {

            case 'multiple_choice_single':
            case 'multiple_choice_multiple':

                return $this->multipleChoiceQuestionForm($survey);
            case 'open_ended':
            default:
                break;
        }
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/question/{question}/type/{questionType}/edit/form",
     *     name="claro_survey_typed_question_edit_form",
     *     options={"expose"=true}
     * )
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function typedQuestionEditFormAction(
        Question $question,
        Survey $survey,
        $questionType
    )
    {
        $this->checkQuestionRight($survey, $question, 'EDIT');

        switch ($questionType) {

            case 'multiple_choice_single':
            case 'multiple_choice_multiple':

                return $this->multipleChoiceQuestionForm(
                    $survey,
                    $question
                );
            case 'open_ended':
            default:
                break;
        }
    }

    /**
     * @EXT\Route(
     *     "/survey/question/relation/{relation}/switch",
     *     name="claro_survey_question_relation_mandatory_switch",
     *     options={"expose"=true}
     * )
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyQuestionRelationMandatorySwitchAction(
        SurveyQuestionRelation $relation
    )
    {
        $survey = $relation->getSurvey();
        $this->checkSurveyRight($survey, 'EDIT');

        $relation->switchMandatory();
        $this->surveyManager->persistSurveyQuestionRelation($relation);
        $data = $relation->getMandatory() ? 'mandatory' : 'not_mandatory';

        return new Response($data, 200);
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/answer/form",
     *     name="claro_survey_answer_form"
     * )
     * @EXT\ParamConverter("user", options={"authenticatedUser" = true})
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyAnswerFormAction(Survey $survey, User $user)
    {
        $this->checkSurveyRight($survey, 'OPEN');
        $status = $this->computeStatus($survey);
        $questionViews = array();

        $surveyAnswer = $this->surveyManager
            ->getSurveyAnswerBySurveyAndUser($survey, $user);
        $answersDatas = array();
        $canEdit = $status === 'published' &&
            (is_null($surveyAnswer) || $survey->getAllowAnswerEdition());

        if (!is_null($surveyAnswer)) {
            $questionsAnswers = $surveyAnswer->getQuestionsAnswers();
            
            foreach ($questionsAnswers as $questionAnswer) {
                $question = $questionAnswer->getQuestion();
                $questionId = $question->getId();
                $answersDatas[$questionId] = array();

                if (!is_null($questionAnswer->getComment())) {
                    $answersDatas[$questionId]['comment'] = $questionAnswer->getComment();
                }

                if ($question->getType() === 'open_ended') {
                    $openEndedAnswer = $this->surveyManager
                        ->getOpenEndedAnswerByUserAndSurveyAndQuestion(
                            $user,
                            $survey,
                            $question
                        );

                    if (!is_null($openEndedAnswer)) {
                        $answersDatas[$questionId]['answer'] =
                            $openEndedAnswer->getContent();
                    }
                } elseif ($question->getType() === 'multiple_choice_single' ||
                        $question->getType() === 'multiple_choice_single') {

                    $choiceAnswers = $this->surveyManager
                        ->getMultipleChoiceAnswersByUserAndSurveyAndQuestion(
                            $user,
                            $survey,
                            $question
                        );

                    foreach ($choiceAnswers as $choiceAnswer) {
                        $choiceId = $choiceAnswer->getChoice()->getId();
                        $answersDatas[$questionId][$choiceId] = $choiceId;
                    }
                }
            }
        }

        foreach ($survey->getQuestionRelations() as $relation) {
            $question = $relation->getQuestion();
            $questionAnswer = isset($answersDatas[$question->getId()]) ?
                $answersDatas[$question->getId()] :
                array();
            $questionViews[] = $this->displayTypedQuestion(
                $survey,
                $question,
                $questionAnswer,
                $canEdit
            )->getContent();
        }

        return array(
            'survey' => $survey,
            'questionRelations' => $survey->getQuestionRelations(),
            'questionViews' => $questionViews,
            'canEdit' => $canEdit
        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/answer",
     *     name="claro_survey_answer"
     * )
     * @EXT\ParamConverter("user", options={"authenticatedUser" = true})
     * @EXT\Template("ClarolineSurveyBundle:Survey:surveyAnswerForm.html.twig")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyAnswerAction(Survey $survey, User $user)
    {
        $this->checkSurveyRight($survey, 'OPEN');
        $status = $this->computeStatus($survey);

        if ($status === 'published') {
            $postDatas = $this->request->getCurrentRequest()->request->all();

            $surveyAnswer = $this->surveyManager
                ->getSurveyAnswerBySurveyAndUser($survey, $user);
            $isNewAnswer = true;

            if (is_null($surveyAnswer)) {
                $surveyAnswer = new SurveyAnswer();
                $surveyAnswer->setSurvey($survey);
                $surveyAnswer->setUser($user);
                $surveyAnswer->setNbAnswers(1);
                $surveyAnswer->setAnswerDate(new \DateTime());
                $this->surveyManager->persistSurveyAnswer($surveyAnswer);
            } else {
                $isNewAnswer = false;
                $surveyAnswer->incrementNbAnswers();
                $this->surveyManager->persistSurveyAnswer($surveyAnswer);
            }

            foreach ($postDatas as $questionId => $questionResponse) {
                $question = $this->surveyManager->getQuestionById($questionId);

                if (!is_null($question)) {
                    $questionType = $question->getType();

                    if ($isNewAnswer) {
                        $questionAnswer = new QuestionAnswer();
                        $questionAnswer->setSurveyAnswer($surveyAnswer);
                        $questionAnswer->setQuestion($question);

                        if (isset($questionResponse['comment']) &&
                            !empty($questionResponse['comment'])) {

                            $questionAnswer->setComment($questionResponse['comment']);
                        }
                        $this->surveyManager->persistQuestionAnswer($questionAnswer);

                        if ($questionType === 'open_ended' &&
                            isset($questionResponse['answer']) &&
                            !empty($questionResponse['answer'])) {

                            $openEndedAnswer = new OpenEndedQuestionAnswer();
                            $openEndedAnswer->setQuestionAnswer($questionAnswer);
                            $openEndedAnswer->setContent($questionResponse['answer']);
                            $this->surveyManager
                                ->persistOpenEndedQuestionAnswer($openEndedAnswer);

                        } elseif ($questionType === 'multiple_choice_single' ||
                                $questionType === 'multiple_choice_multiple') {

                            $multipleChoiceQuestion = $this->surveyManager
                                ->getMultipleChoiceQuestionByQuestion($question);

                            if (!is_null($multipleChoiceQuestion)) {

                                if ($questionType === 'multiple_choice_multiple') {

                                    foreach($questionResponse as $choiceId => $response) {

                                        if ($choiceId !== 'comment') {
                                            $choice = $this->surveyManager->getChoiceById($choiceId);
                                            $choiceAnswer = new MultipleChoiceQuestionAnswer();
                                            $choiceAnswer->setQuestionAnswer($questionAnswer);
                                            $choiceAnswer->setChoice($choice);
                                            $this->surveyManager
                                                ->persistMultipleChoiceQuestionAnswer($choiceAnswer);
                                        }
                                    }
                                } elseif ($questionType === 'multiple_choice_single' &&
                                    isset($questionResponse['choice']) &&
                                    !empty($questionResponse['choice'])) {

                                    $choiceId = (int)$questionResponse['choice'];
                                    $choice = $this->surveyManager->getChoiceById($choiceId);
                                    $choiceAnswer = new MultipleChoiceQuestionAnswer();
                                    $choiceAnswer->setQuestionAnswer($questionAnswer);
                                    $choiceAnswer->setChoice($choice);
                                    $this->surveyManager
                                        ->persistMultipleChoiceQuestionAnswer($choiceAnswer);
                                }
                            }
                        }

                    } elseif ($survey->getAllowAnswerEdition()) {
                        $questionAnswer = $this->surveyManager
                            ->getQuestionAnswerBySurveyAnswerAndQuestion(
                                $surveyAnswer,
                                $question
                            );

                        if (is_null($questionAnswer)) {
                            $questionAnswer = new QuestionAnswer();
                            $questionAnswer->setSurveyAnswer($surveyAnswer);
                            $questionAnswer->setQuestion($question);
                            $this->surveyManager->persistQuestionAnswer($questionAnswer);
                        }

                        if (isset($questionResponse['comment']) &&
                            !empty($questionResponse['comment'])) {

                            $questionAnswer->setComment($questionResponse['comment']);
                            $this->surveyManager->persistQuestionAnswer($questionAnswer);
                        }

                        if ($questionType === 'open_ended' &&
                            isset($questionResponse['answer']) &&
                            !empty($questionResponse['answer'])) {

                            $openEndedAnswer = $this->surveyManager
                                ->getOpenEndedAnswerByQuestionAnswer($questionAnswer);

                            if (is_null($openEndedAnswer)) {
                                $openEndedAnswer = new OpenEndedQuestionAnswer();
                                $openEndedAnswer->setQuestionAnswer($questionAnswer);
                            }
                            $openEndedAnswer->setContent($questionResponse['answer']);
                            $this->surveyManager
                                ->persistOpenEndedQuestionAnswer($openEndedAnswer);

                        } elseif ($questionType === 'multiple_choice_single' ||
                                $questionType === 'multiple_choice_multiple') {

                            $multipleChoiceQuestion = $this->surveyManager
                                ->getMultipleChoiceQuestionByQuestion($question);

                            if (!is_null($multipleChoiceQuestion)) {

                                if ($questionType === 'multiple_choice_multiple') {

                                    $this->surveyManager
                                        ->deleteMultipleChoiceAnswersByQuestionAnswer($questionAnswer);

                                    foreach($questionResponse as $choiceId => $response) {

                                        if ($choiceId !== 'comment') {
                                            $choice = $this->surveyManager->getChoiceById($choiceId);
                                            $choiceAnswer = new MultipleChoiceQuestionAnswer();
                                            $choiceAnswer->setQuestionAnswer($questionAnswer);
                                            $choiceAnswer->setChoice($choice);
                                            $this->surveyManager
                                                ->persistMultipleChoiceQuestionAnswer($choiceAnswer);
                                        }
                                    }
                                } elseif ($questionType === 'multiple_choice_single' &&
                                    isset($questionResponse['choice']) &&
                                    !empty($questionResponse['choice'])) {

                                    $this->surveyManager
                                        ->deleteMultipleChoiceAnswersByQuestionAnswer($questionAnswer);

                                    $choiceId = (int)$questionResponse['choice'];
                                    $choice = $this->surveyManager->getChoiceById($choiceId);
                                    $choiceAnswer = new MultipleChoiceQuestionAnswer();
                                    $choiceAnswer->setQuestionAnswer($questionAnswer);
                                    $choiceAnswer->setChoice($choice);
                                    $this->surveyManager
                                        ->persistMultipleChoiceQuestionAnswer($choiceAnswer);
                                }
                            }
                        }
                    }
                }
            }
        }

        return new RedirectResponse(
            $this->router->generate(
                'claro_survey_index',
                array('survey' => $survey->getId())
            )
        );

//        $questionViews = array();
//
//        foreach ($survey->getQuestionRelations() as $relation) {
//            $question = $relation->getQuestion();
//            $questionViews[] =
//                $this->typedQuestionDisplayAction($survey, $question)->getContent();
//        }
//
//        return array(
//            'survey' => $survey,
//            'questionRelations' => $survey->getQuestionRelations(),
//            'questionViews' => $questionViews
//        );
    }

    /**
     * @EXT\Route(
     *     "/survey/{survey}/results/show/question/{question}/page/{page}/max/{max}",
     *     name="claro_survey_results_show",
     *     defaults={"page"=1, "max"=20}
     * )
     * @EXT\ParamConverter("user", options={"authenticatedUser" = true})
     * @EXT\Template()
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function surveyResultsShowAction(
        Survey $survey,
        Question $question,
        $page = 1,
        $max = 20
    )
    {
        $canEdit = $this->hasSurveyRight($survey, 'EDIT');

        if (!$canEdit && !$survey->getHasPublicResult()) {

            throw new AccessDeniedException();
        }
        $questionRelations = $survey->getQuestionRelations();
        $questions = array();

        foreach ($questionRelations as $relation) {
            $questions[] = $relation->getQuestion();
        }

        $results = $this->showTypedQuestionResults($survey, $question, $page, $max)
            ->getContent();

        return array(
            'survey' => $survey,
            'questions' => $questions,
            'currentQuestion' => $question,
            'results' => $results
        );
    }

    private function showTypedQuestionResults(
        Survey $survey,
        Question $question,
        $page = 1,
        $max = 20
    )
    {
        $questionType = $question->getType();

        switch ($questionType) {

            case 'multiple_choice_single' :
            case 'multiple_choice_multiple' :

                return $this->showMultipleChoiceQuestionResults(
                    $survey,
                    $question
                );
            case 'open_ended':

                return $this->showOpenEndedQuestionResults(
                    $survey,
                    $question,
                    $page,
                    $max
                );
            default:
                break;
        }

        return new Response();
    }

    private function showMultipleChoiceQuestionResults(
        Survey $survey,
        Question $question
    )
    {
        $choices = $this->surveyManager->getChoicesByQuestion($question);
        $choicesCount = array();
        $nbRespondents = 0;

        $respondents = $this->surveyManager
            ->countQuestionAnswersBySurveyAndQuestion($survey, $question);

        if (!is_null($respondents)) {
            $nbRespondents = $respondents['nb_answers'];

            foreach ($choices as $choice) {
                $count = $this->surveyManager
                    ->countMultipleChoiceAnswersBySurveyAndChoice($survey, $choice);

                if (!is_null($count)) {
                    $choicesCount[$choice->getId()] = $count['nb_answers'];
                }
            }
        }

        return new Response(
            $this->templating->render(
                "ClarolineSurveyBundle:Survey:showMultipleChoiceQuestionResults.html.twig",
                array(
                    'choices' => $choices,
                    'choicesCount' => $choicesCount,
                    'nbRespondents' => $nbRespondents
                )
            )
        );
    }

    private function showOpenEndedQuestionResults(
        Survey $survey,
        Question $question,
        $page = 1,
        $max = 20
    )
    {
        $answers = $this->surveyManager->getOpenEndedAnswersBySurveyAndQuestion(
            $survey,
            $question,
            $page,
            $max
        );

        return new Response(
            $this->templating->render(
                "ClarolineSurveyBundle:Survey:showOpenEndedQuestionResults.html.twig",
                array(
                    'survey' => $survey,
                    'question' => $question,
                    'answers' => $answers,
                    'max' => $max
                )
            )
        );
    }

    private function displayTypedQuestion(
        Survey $survey,
        Question $question,
        array $answers,
        $canEdit = true
    )
    {
        $this->checkQuestionRight($survey, $question, 'OPEN');
        $questionType = $question->getType();

        switch ($questionType) {

            case 'multiple_choice_single' :
            case 'multiple_choice_multiple' :

                return $this->displayMultipleChoiceQuestion(
                    $question,
                    $answers,
                    $canEdit
                );
            case 'open_ended':

                return $this->displayOpenEndedQuestion(
                    $question,
                    $answers,
                    $canEdit
                );
            default:
                break;
        }

        return new Response();
    }

    private function multipleChoiceQuestionForm(
        Survey $survey,
        Question $question = null
    )
    {
        $multipleChoiceQuestion = is_null($question) ?
            null :
            $this->surveyManager->getMultipleChoiceQuestionByQuestion($question);
        $choices = array();
        $horizontal = false;

        if (!is_null($multipleChoiceQuestion)) {
            $choices = $multipleChoiceQuestion->getChoices();
            $horizontal = $multipleChoiceQuestion->getHorizontal();
        }

        return new Response(
            $this->templating->render(
                "ClarolineSurveyBundle:Survey:multipleChoiceQuestionForm.html.twig",
                array(
                    'survey' => $survey,
                    'horizontal' => $horizontal,
                    'choices' => $choices
                )
            )
        );
    }

    private function displayMultipleChoiceQuestion(
        Question $question,
        array $answers = null,
        $canEdit = true
    )
    {
        $multipleChoiceQuestion = $this->surveyManager
            ->getMultipleChoiceQuestionByQuestion($question);

        if (is_null($multipleChoiceQuestion)) {

            throw new \Exception('Cannot find multiple choice question');
        }

        $choices = $multipleChoiceQuestion->getChoices();
        $answersDatas = is_null($answers) ? array() : $answers;

        return new Response(
            $this->templating->render(
                "ClarolineSurveyBundle:Survey:displayMultipleChoiceQuestion.html.twig",
                array(
                    'question' => $question,
                    'choices' => $choices,
                    'answers' => $answersDatas,
                    'canEdit' => $canEdit,
                    'horizontal' => $multipleChoiceQuestion->getHorizontal()
                )
            )
        );
    }

    private function displayOpenEndedQuestion(
        Question $question,
        array $answers = null,
        $canEdit = true
    )
    {
        $answersDatas = is_null($answers) ? array() : $answers;

        return new Response(
            $this->templating->render(
                "ClarolineSurveyBundle:Survey:displayOpenEndedQuestion.html.twig",
                array(
                    'question' => $question,
                    'answers' => $answersDatas,
                    'canEdit' => $canEdit
                )
            )
        );
    }

    private function updateMultipleChoiceQuestion(
        Question $question,
        array $datas
    )
    {
        $horizontal = isset($datas['choice-display']) &&
            ($datas['choice-display'] === 'horizontal');
        $choices = isset($datas['choice']) ?
            $datas['choice'] :
            array();

        $multipleChoiceQuestion = $this->surveyManager
            ->getMultipleChoiceQuestionByQuestion($question);

        if (is_null($multipleChoiceQuestion)) {
            $this->surveyManager->createMultipleChoiceQuestion(
                $question,
                $horizontal,
                $choices
            );
        } else {
            $this->surveyManager->updateQuestionChoices(
                $multipleChoiceQuestion,
                $horizontal,
                $choices
            );
        }
    }

    private function computeStatus(Survey $survey)
    {
        $status = 'unpublished';

        if ($survey->isPublished() && !$survey->isClosed()) {
            $status = 'published';
        } elseif ($survey->isClosed()) {
            $status = 'closed';
        }

        return $status;
    }

    private function checkSurveyRight(Survey $survey, $right)
    {
        $collection = new ResourceCollection(array($survey->getResourceNode()));

        if (!$this->security->isGranted($right, $collection)) {
            
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }
    }

    private function hasSurveyRight(Survey $survey, $right)
    {
        $collection = new ResourceCollection(array($survey->getResourceNode()));

        return $this->security->isGranted($right, $collection);
    }

    private function checkQuestionRight(Survey $survey, Question $question, $right)
    {
        $this->checkSurveyRight($survey, $right);
        $surveyWorkspaceId = $survey->getResourceNode()->getWorkspace()->getId();
        $questionWorkspaceId = $question->getWorkspace()->getId();

        if ($surveyWorkspaceId !== $questionWorkspaceId) {

            throw new AccessDeniedException();
        }
    }
}
