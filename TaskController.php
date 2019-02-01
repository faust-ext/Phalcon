<?php

namespace Controllers\Consultations;

use Controllers\ControllerBase;
use Forms\Consultations\RatingRequest;
use Forms\Consultations\VoicesCreate;
use Forms\Consultations\RequestTicketCommentCreate;
use Forms\Consultations\WrittenCreate;
use Models\AssignedService;
use Models\Consultations\RequestEvaluation;
use Models\Consultations\RequestTicketCallfiles;
use Models\Consultations\RequestTicketComments;
use Models\Consultations\RequestTicketFiles;
use Models\Consultations\RequestTickets;
use Models\Ticket,
    Models\CustomerQuotas;
use Models\TicketHistory;
use Models\User;
use Phalcon\Exception;
use Phalcon\Validation;

class TaskController extends ControllerBase
{
    public function indexAction()
    {
        $roleLists = $this->user->getRolesRecurcive();
//todo:   для  $writtenType $voicesType     Загнать головую и письменую консультацию в тарифы чтобы определить, что доступно и то тянуть

        $isAdvisor = in_array("legalConsultant", $roleLists);
        $isClient = in_array("legalUser", $roleLists);

        if ($isAdvisor) {
            $this->view->setVar('tickets', RequestTickets::getTickets());
            $this->view->setVar('currentUser', 'legalConsultant');
        } elseif ($isClient) {
            $writtenType = in_array("writtenCons", $roleLists);
            $voicesType = in_array("voicesCons", $roleLists);
            $this->view->setVar('tickets', RequestTickets::getTicketsForUser($this->user, $writtenType, $voicesType));
            $quotas = CustomerQuotas::getAvailable($this->user->id, "'scribes'");
            $this->view->setVar('quotas', $quotas);
            $this->view->setVar('currentUser', 'client');

        }
        $this->viewer->addPlugin('consultations');
        $this->viewer->addPlugin('table');
        $this->view->title = $this->lang->_("consultations-list");
    }

    public function detailAction($id)
    {
        $this->viewer->addPlugin('consultations');

        //todo:   для  $writtenType $voicesType     Загнать головую и письменую консультацию в тарифы чтобы определить, что доступно и то тянуть
        $roleLists = $this->user->getRolesRecurcive();
        $isAdvisor = in_array("legalConsultant", $roleLists);
        $isClient = in_array("leagalUser", $roleLists);
        $this->view->setvar('currentUser', $isAdvisor ? 'legalConsultant' : 'client');

        $component = new \Components\Lang;
        $translate = $component->getTranslate('default', '', 'array');
        $ticket = RequestTickets::findFirst($id);

        $form = new RequestTicketCommentCreate($ticket);

        $form->setAjax();
        $form->setEntity($ticket);
        $reqEvaluation = RequestEvaluation::findFirst("ticket_id = $ticket->id");
        $rForm = new RatingRequest($reqEvaluation);

        if (!$ticket) {
            $this->flash->danger($translate['ticket-not-find']);
            return $this->dispatcher->forward(array(
                'action' => 'index'
            ));
        }

        $ticket_comments = RequestTicketComments::find([
            "ticket_id = ?1",
            "order" => "created_at DESC",
            "bind" => [1 => $id]
        ]);
        $ticket_files = RequestTicketFiles::find([
            "parent_id = ?1 AND belongs_to=?2",
            "bind" => [1 => $id, 2 => 'ticket']
        ]);

        $this->view->setVar('ticket', $ticket);
        $this->view->setVar('form', $form);
        $this->view->setVar('rForm', $rForm);
        $this->view->setVar('ratingTicket',$reqEvaluation);
        $this->view->setVar('ticket_comments', $ticket_comments);
        $this->view->setVar('ticket_files', $ticket_files);
        $this->view->title = $this->lang->_("consultation-detail");
        $this->setBreadcrumbs(['/consultations']);
    }

    public function changeStatusRequestAction()
    {
        $ticket = RequestTickets::findFirst($_POST['ticket_id']);
        $status = $_POST['action'] == 'closeConsultation' ? 5 : 4;
        $ticket->assign([
            'status' => $status,
            'user_closed' => $_POST['user_closed']
        ]);
        if ($ticket->save()) {
            $this->flashMessage('success', $this->lang->_("requestTicketClosed"));
        } else {
            $this->flashMessage('danger', $this->lang->_("changeStatusError"));
        }

        return $this->returnData();
    }

    public function createWrittenAction()
    {
        $this->view->title = $this->lang->_("requestWrittenCreate");

        $ticket = new RequestTickets();
        $ticket->assign(['type_ticket' => RequestTickets::TYPE_WRITTEN]);
        $form = new WrittenCreate($ticket);
        $form->setAjax();
        $this->view->setVar('form', $form);
        $this->setBreadcrumbs(['consultations']);
    }

    public function createVoicesAction()
    {
        $component = new \Components\Lang;
        $translate = $component->getTranslate('default', '', 'array');
        $this->view->title = $this->lang->_("requestVoicesCreate");
        $xz=CustomerQuotas::findClients();
        $form = new VoicesCreate();
        $form->setAjax();
        $this->view->setVar('form', $form);
        $this->setBreadcrumbs(['consultations']);
    }

    /**
     * Обработка создания письменных и телефонных консультаций
     * @return \Phalcon\Http\Response|\Phalcon\Http\ResponseInterface|void
     */
    public function createRequestAction()
    {
        $component = new \Components\Lang;
        $translate = $component->getTranslate('default', '', 'array');
        $user = $this->auth->getUser();
        $ticket = new RequestTickets();
        if (isset($_POST['type_ticket']) && $_POST['type_ticket'] == requestTickets::TYPE_WRITTEN) {
            $type = RequestTickets::TYPE_WRITTEN;
            $entity='scribes';
            $clientId = $user->id;
            // todo:Сделать автоматическое распределение в зависимости от нагруженности юриста
            $operatorId = array_rand(RequestTickets::findUsersByRole('legalConsultant'), 1);
            $form = new WrittenCreate($ticket);
        } else {
            $type = RequestTickets::TYPE_VOICES;
            $entity = 'calls';
            $clientId = $this->request->getPost('client_id');
            $operatorId = $user->id;
            $form = new VoicesCreate($ticket);
        }
        if (!$form->isValid($this->request->getPost())) {
            foreach ($form->getMessages() as $message) {
                $this->flashMessage('fields', $message, $message->getField());
            }
        }

        $ticket->assign([
            'header' => $this->request->getPost('header'),
            'text' => $this->request->getPost('text'),
            'company_profile_id' => 1,
            'client_id' => $clientId,
            'operator_id' => $operatorId,
            'status' => 2,
            'type_ticket' => $type
        ]);
        $paid = CustomerQuotas::getAvailable($clientId,"'$entity'")->getFirst();

        $subQuatas= new CustomerQuotas();
        $subQuatas->assign([
            'user_id'=>$clientId,
            'entity'=>$entity,
            'quantity'=>'-1',
            'purchase_id'=>$paid->purchase_id,
        ]);

        if ($ticket->save() && $subQuatas->save()) {
            RequestEvaluation::generateData($ticket->id);
            if ($this->request->hasFiles()) {
                foreach ($this->request->getUploadedFiles() as $file) {
                    if (empty($file->getName()) or empty($file->getSize()))
                        continue;

                    try {
                        $filepath = '/upload/request/scribe' . $ticket->id;
                        $full_filepath = $filepath . '/' . $file->getName();
                        $success_upload = $this->fs->saveLocalFile($filepath, $file, $file->getName(), false);
                    } catch (Exception $e) {
                        $this->flashMessage('fields', new Validation\Message($translate['ticket-file-error']), 'file');
                    }

                    $fileModel = new RequestTicketFiles();
                    $fileModel->name = "File to " . $ticket->name;
                    $fileModel->parent_id = $ticket->id;
                    $fileModel->filename = $file->getName();
                    $fileModel->attribute = 'none';
                    $fileModel->belongs_to = 'ticket';
                    if (!$fileModel->save()) {
                        $this->flashMessage('fields', new Validation\Message($translate['ticket-file-error']), 'file');
                    }
                }
            }
            if ($type === RequestTickets::TYPE_VOICES) {
                $this->flashMessage('success', $this->lang->_("requestTicketCreated"));
            }
        } else {
            foreach ($ticket->getMessages() as $m) {
                $this->flashMessage('fields', $m, $m->getField());
            }
        }
        if ($this->request->isAjax()) {
            if ($ticket->id != 0) {
                if ($type === RequestTickets::TYPE_VOICES) {
                    $this->ajax['actions'][] = array('redirect' => '/consultations/addrecord/' . $ticket->id . '/ticket');
                    return $this->returnData();
                }
                $this->ajax['actions'][] = array('redirect' => '/consultations/detail/' . $ticket->id);
            }
        }
        return $this->returnData();
    }


    public function fileAction($id, $xz = null)
    {
        $this->view->disable();
        while (ob_get_level()) {
            ob_end_clean();
        }


        if ($xz === 'record') {
            $file = RequestTicketCallfiles::findFirstById($id);
        } else {
            $file = RequestTicketFiles::findFirstById($id);
        }

        if (!$file) {
            $this->log->error(__CLASS__ . __METHOD__ . ': Запрос несуществующего файла');
            return;
        }

        $file_extension = end(explode(".", $file->filename));
        switch ($file_extension) {
            case "pdf":
                $ctype = "application/pdf";
                break;
            case "exe":
                $ctype = "application/octet-stream";
                break;
            case "zip":
                $ctype = "application/zip";
                break;
            case "doc":
                $ctype = "application/msword";
                break;
            case "xls":
                $ctype = "application/vnd.ms-excel";
                break;
            case "ppt":
                $ctype = "application/vnd.ms-powerpoint";
                break;
            case "gif":
                $ctype = "image/gif";
                break;
            case "png":
                $ctype = "image/png";
                break;
            case "mp3":
                $ctype = "audio/mpeg";
                break;
            case "jpeg":
            case "jpg":
                $ctype = "image/jpg";
                break;
            default:
                $ctype = "application/force-download";
        }


        if ($xz === 'record') {
            $filePath = $this->config->files->uploadDir . $file->getFilePath();
        } elseif ($file->belongs_to == "comment") {
            $filePath = $this->config->files->uploadDir . $file->getFilePathComment();
        } else {
            $filePath = $this->config->files->uploadDir . $file->getFilePathTicket();
        }

        $filePath = str_replace("/", "\\", $filePath);
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private', false); // нужен для Explorer
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $file->filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length:' . filesize($filePath));
        readfile($filePath);
        exit();
    }

    public function createCommentAction($ticket_id)
    {
        $component = new \Components\Lang;
        $translate = $component->getTranslate('default', '', 'array');
        $this->view->title = $this->lang->_("requestTicketCommentCreate");
        $ticket = RequestTickets::findFirst($ticket_id);
        $form = new RequestTicketCommentCreate($ticket);
        $form->setAjax();
        $this->view->setVar('form', $form);
        $this->setBreadcrumbs(['requests']);
    }

    public function createCommentRequestAction()
    {

        $component = new \Components\Lang;
        $translate = $component->getTranslate('default', '', 'array');
        $user = $this->auth->getUser();

        $comment = new RequestTicketComments();
        $form = new RequestTicketCommentCreate($comment);

        if (!$form->isValid($this->request->getPost())) {
            foreach ($form->getMessages() as $message) {
                $this->flashMessage('fields', $message, $message->getField());
            }
        } else {
            $comment->assign([
                'text' => $this->request->getPost('content-comment'),
                'ticket_id' => $this->request->getPost('ticket_id'),
                'user_id' => $user->id,
            ]);
            if ($comment->save()) {

                if (!empty($this->request->getPost('records'))) {
                    $arr = explode(',', $this->request->getPost('records'));
                    $post = ['id' => $comment->id, 'assoc' => 'comment'];
                    foreach ($arr as $key => $item) {
                        $addRecords = RequestTicketCallfiles::assocRecordWitchTicket($item, $post);
                    }
                }

                if ($this->request->hasFiles()) {
                    foreach ($this->request->getUploadedFiles() as $file) {
                        if (empty($file->getName()) or empty($file->getSize()))
                            continue;

                        try {
                            $filepath = '/upload/request/comment' . $comment->id;
                            $full_filepath = $filepath . '/' . $file->getName();
                            $success_upload = $this->fs->saveLocalFile($filepath, $file, $file->getName(), false);
                            $fileModel = new RequestTicketFiles();
                            $fileModel->name = "File to " . $this->request->getPost('name');
                            $fileModel->parent_id = $comment->id;
                            $fileModel->filename = $file->getName();
                            $fileModel->attribute = 'none';
                            $fileModel->belongs_to = 'comment';
                            if (!$fileModel->save()) {
                                $this->flashMessage('fields', new Validation\Message($translate['ticket-file-error']), 'file');
                            }
                        } catch (Exception $e) {
                            $this->flashMessage('fields', new Validation\Message($translate['ticket-file-error']), 'file');
                        }
                    }
                }
                $this->flashMessage('success', $this->lang->_("CommentCreated"));
            } else {
                foreach ($comment->getMessages() as $m) {
                    $this->flashMessage('fields', $m, $m->getField());
                }
            }
        }
        if ($this->request->isAjax()) {
            if ($comment->ticket_id != 0) {
                $this->ajax['actions'][] = array('redirect' => '/consultations/detail/' . $comment->ticket_id);
            }
            return self::returnData();
        }
        return $this->returnData();
    }

    public function addCommentRequestAction()
    {
        $component = new \Components\Lang;
        $translate = $component->getTranslate('default', '', 'array');

        $ticketHistory = new TicketHistory();
        $userId = $this->auth->getUser()->getId();

        $ticketHistory->ticket_id = $this->request->getPost('ticketId', 'int');
        $ticketHistory->autor_id = $userId;
        $ticketHistory->comment = $this->request->getPost('comment', 'string');

        $ticket = Ticket::findFirst($ticketHistory->ticket_id);
        if (!$ticket) {
            $this->flashMessage('fields', new Validation\Message('ticket-not-found-error'), 'file');
            if ($this->request->isAjax()) {
                $this->ajax['actions'][] = ['redirect' => '/ticket/'];
                return self::returnData();
            } else {
                return $this->dispatcher->forward(['action' => 'index']);
            }
        }

        $form = new RequestTicketCommentCreate($ticket);
        $form->setAjax();
        $form->setEntity($ticket);
        if (!$form->isValid($this->request->getPost())) {
            foreach ($form->getMessages() as $message) {
                $this->flashMessage('fields', $message, $message->getField());
            }
            return self::returnData();
        }

        if ($ticketHistory->save()) {
            if ($this->request->hasFiles()) {
                foreach ($this->request->getUploadedFiles() as $file) {
                    if (empty($file->getName()))
                        continue;

                    try {
                        $this->fs->saveCommentFile($this->request->getPost('ticketId', 'int'), $ticketHistory->id, $file);
                    } catch (Exception $e) {
                        $this->flashMessage('fields', new Validation\Message($e->getMessage()), 'file');
                        return self::returnData();
                    }
                    $fileModel = new File();
                    $fileModel->ticket_id = $this->request->getPost('ticketId', 'int');
                    $fileModel->filename = $file->getName();
                    $fileModel->mime = $file->getType();
                    $fileModel->ticket_history_id = $ticketHistory->id;

                    if ($fileModel->save()) {

                    } else {
                        $this->flashMessage('fields', new Validation\Message($translate['ticket-file-error']), 'file');
                        return self::returnData();
                    }

                }
            }
        } else {
            foreach ($ticketHistory->getMessages() as $m) {
                $this->flashMessage('fields', $m, $m->getField());
            }
            return self::returnData();
        }

        if ($this->request->isAjax()) {
            if ($this->request->getPost('ticketId', 'int') != 0) {
                $this->ajax['actions'][] = array('redirect' => '/ticket/' . $this->request->getPost('ticketId', 'int') . '/');
            }
            return self::returnData();
        } else {
            return $this->dispatcher->forward(array(
                'controller' => 'ticket',
                'action' => 'index',
                'ticketId' => $this->request->getPost('ticketId', 'int')
            ));
        }
    }

    public function checkSecretWordRequestAction()
    {
        $clientId = $this->request->getPost('client_id');
        $word = $this->request->getPost('word');
        $client = User::findFirst("id=$clientId");

        if ($word === $client->secretWord) {
            $result = true;
            $quotas = CustomerQuotas::getAvailableService($clientId, "'calls'");
            $this->flashMessage('success', "Успешно проверили");
            $this->ajax['tickets'] = RequestTickets::getArrTicketsForUser($clientId, true, true);
            $this->ajax['quotas'] = $quotas;
        } else {
            $result = false;
            $this->flashMessage('danger', $this->lang->_("Не прошел проверку"));
        }
        $this->ajax['result'] = $result;

        return self::returnData();
    }

    public function addRecordAction($id, $assoc)
    {
        $this->viewer->addPlugin('consultations');
        $this->viewer->addPlugin('table');
        $ticket = RequestTickets::findFirst($id);
        $records = RequestTicketCallfiles::find();
        if ($assoc == 'comment') {
            $form = new RequestTicketCommentCreate($ticket);
            $form->setAjax();
            $this->view->setVar('form', $form);
        }
        $this->view->setVar('ticket', $ticket);
        $this->view->setVar('assoc', $assoc);
        $this->view->setVar('records', $records);
    }


    public function assocRecordWithTicketRequestAction()
    {
        foreach ($_POST['arr'] as $key => $item) {
            $res = RequestTicketCallfiles::assocRecordWitchTicket($item, $_POST);
            if ($res) {
                $this->ajax['result'] = "success";
                if ($_POST['action'] == 'unfixed') {
                    $this->flashMessage('success', "Открепили запись");
                } else {
                    $this->flashMessage('success', "Прикрепили запись");
                }
            } else {
                $this->flashMessage('danger', "запись $item не записалась");
            }
        }
        $this->ajax['actions'][] = array('redirect' => '/consultations/detail/' . $_POST['id']);

        return self::returnData();
    }

//    public function assocRecordWithCommentRequestAction()
//    {
//        RequestTicketCallfiles::assocCommentWithComment();
//    }

    public function hideRecordsRequestAction()
    {
        $result = RequestTicketCallfiles::hideRecords($_POST['arr']);

        $this->ajax['result'] = $result ? 'success' : 'danger';
        if ($result && $_POST['action'] == 'show') {
            $this->flashMessage('success', "Записи раскрыты");
        } elseif ($result && $_POST['action'] == 'hide') {
            $this->flashMessage('success', "Записи скрыты");
        }

        return $this->returnData();
    }

    public function createRatingTicketRequestAction(){
        if($this->request->isAjax()){
            $comment = $this->request->getPost('comment');
            $mark = $this->request->getPost('mark');
            $ticket_id =$this->request->getPost('ticket_id');

            $reqEvaluation = RequestEvaluation::findFirst("ticket_id=$ticket_id");
            $reqEvaluation->assign([
                'voted'=>1,
                'mark'=>$mark,
                'vote_enable'=>1,
                'comment' =>$comment,
            ]);
            $result=$reqEvaluation->save();
            if ($result) {
                $this->ajax['result']='success';
                $this->ajax['actions'][] = array('redirect' => '/consultations/detail/' . $ticket_id);
                $this->flashMessage('success', "Отзыв успешно сохранен");
            } else {
                $this->ajax['result']='danger';
                $this->flashMessage('danger', "Ошибка");
            }
        }

        return $this->returnData();
    }

}