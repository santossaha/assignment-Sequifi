<?php

//for offer letter offer_letter_signed
/*
=============================================== for offer letter offer_letter_signed =============================================== 
*/
public function applyDigiSign(Request $request){

        $start_time = microtime(true);  

        $doc_id     = $request->document_id;
        $signer_id  = $request->signer_id;
        $pdfPath    = $request->pdfPath; 

        $document = EnvelopeDocument::with([
            'active_document',
            'document_signer.visible_signatures_and_form_data_attributes'
        ])->whereHas('active_document')
        ->whereHas('document_signer')->find($doc_id);

        $digitallySignedPdfPath = $this->pdf_ssldotcom_digi_signer($pdfPath, $doc_id);
        $path = $pdfPath;
        if($digitallySignedPdfPath){
            $path = $digitallySignedPdfPath;
        }
        $app_path = storage_path() . '/app';
        $path = str_replace($app_path, '', $path);

        if (Storage::disk($this->disk)->exists($path)) {

            $fileContent = Storage::disk($this->disk)->get($path);
            $awsPath = env('DOMAIN_NAME').'/'.$path;

            try {
                $obj = s3_upload($awsPath, $fileContent, false, 'private');
                if($obj['status']){
                    $pdf_file_path = s3_getTempUrl($awsPath,'private',60);
                    $env_doc = EnvelopeDocument::find($document->id);
                    $call_back_response = ["response" => [
                        "is_completed" => true,
                        "event_type" => "SIGNATURE_REQUEST_COMPLETED",
                        "signature_request" => [
                            "signature_request_id" => $document->envelope_id, 
                            "is_completed" => true,
                            "documents" => [
                                "signature_request_document_id" => $document->id, 
                                "template_name" => $document->template_name,
                                "category" => $document->template_category_name,
                                "signed_pdf_path" => $pdf_file_path, 
                                "signer_array" => [ 
                                    [
                                        "role" => $document->document_signer->role,
                                        "email" => $document->document_signer->email,
                                        "user_name" => $document->document_signer->signer_name
                                    ]
                                ]
                            ]
                        ]
                    ]];
                    $document_callback_signed_response = NewSequiDocsDocument::new_sequidocs_document_callback_signed_response($call_back_response);
                    $end_time = microtime(true);
                    $execution_time = $end_time - $start_time;
                    Log::debug("Execution Time /document-digital-signature/apply-digi-sign");
                    Log::debug($execution_time . " seconds");
                    if(isset($document->active_document->DocSendTo->rehire) && $document->active_document->DocSendTo->rehire == 1){
                        User::where('id',$document->active_document->DocSendTo->id)
                        ->update([
                            'dismiss' => 0,
                            'status_id' => 1,
                        ]);
                    }
                    if (in_array(env('DOMAIN_NAME'), ['hawx','hawxw2','sstage','milestone'])) {
                        if(isset($document->active_document->DocSendTo->rehire) && $document->active_document->DocSendTo->rehire == 1){
                            User::where('id',$document->active_document->DocSendTo->id)
                            ->update([
                                'dismiss' => 0,
                                'status_id' => 1,
                            ]);
                        }
                    }
                    
                    return response()->json([
                        'status' => true,
                        'message' => 'Digital Signature Process done.',
                        'data' => $env_doc,
                        'document_callback_signed_response' => $document_callback_signed_response,
                        // 'Execution Time' => $execution_time . " seconds",
                    ],200);
                } else {
                    Log::debug("Error in s3 upload");
                    $ApiName = "app/Http/Controllers/DigitalSignature/DigitalSignatureController.php";
                    $new_sequi_docs_signature_request_logs = new NewSequiDocsSignatureRequestLog();
                    $new_sequi_docs_signature_request_logs->ApiName = $ApiName;
                    $new_sequi_docs_signature_request_logs->signature_request_response = [
                        'message' => 'Error in s3 upload'
                    ];
                    $new_sequi_docs_signature_request_logs->save();
                    return response()->json([
                        'status' => false,
                        'message' => 'Digital Signature Process failed.',
                    ],400);
                }
            } catch (\Exception $error) {
                Log::debug("Digital Signature Process failed.");
                Log::debug($error);
                $message = "Digital Signature Process failed.";
                $error_message = $error->getMessage();
                $File  = $error->getFile();
                $Line  = $error->getLine();
                $Code  = $error->getCode();
                $Trace  = $error->getTraceAsString();
                $errorDetail = [
                    "error_message" => $error_message,
                    "File" => $File,
                    "Line" => $Line,
                    "Code" => $Code,
                ];
                return response()->json(['error' => $error, 'message' => $message, 'errorDetail' => $errorDetail ], 400);
            }
        }

    }






/*
=============================================== send offer letter =============================================== 
*/










    //send offer_resent,  sales_rep_signup, sales_rep_signup
    public function send_offer_letter_to_onboarding_employee(Request $request){
        $reportlabOutput = shell_exec('pip3 show reportlab 2>&1');
        if (strpos($reportlabOutput, 'Package(s) not found') !== false || empty($reportlabOutput)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Required Python package reportlab is not installed.',
            ], 500);
        }
        $requestsOutput = shell_exec('pip3 show requests 2>&1');
        if (strpos($requestsOutput, 'Package(s) not found') !== false || empty($requestsOutput)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Required Python package requests is not installed.',
            ], 500);
        }
        $pymupdfOutput = shell_exec('pip3 show pymupdf 2>&1');
        if (strpos($pymupdfOutput, 'Package(s) not found') !== false || empty($pymupdfOutput)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Required Python package PyMuPDF is not installed.',
            ], 500);
        }
        preg_match('/Version:\s([0-9\.]+)/', $pymupdfOutput, $matches);
        $installedVersion = $matches[1] ?? null;
        if ($installedVersion !== '1.23.8') {
            return response()->json([
                'status' => 'error',
                'message' => 'PyMuPDF version 1.23.8 is required, but version ' . $installedVersion . ' is installed.',
            ], 500);
        }
        $ApiName = Route::currentRouteName();
        $status_code = 400;
        $status = false;
        $message = "User not found invailid user id";
        $user_data = [];
        $response_array = [];
        $serverIP = URL::to('/');
        $response = [];
        $pdf_send_count = 0;
        $Document_Access_Password = "";
        $Document_list_is = "";
        $api_call_for = "use";
        $smart_text_template_fied_keyval = null;
        if($request->custom_fields){
            $smart_text_template_fied_keyval = $request->custom_fields;
        }
        $Validator = Validator::make(
            $request->all(),
            [
                'user_id' => 'required|integer',
                'name' => isset($request->type) && $request->type == 'resend'?'nullable':'required',
                'signing_screeen_url' => 'required'
            ]
        );
        if ($Validator->fails()) {
            return response()->json(['error' => $Validator->errors()], 400);
        }
        $all_request_data =  $request->all();
        try {

            $employeeIdSettingData = EmployeeIdSetting::first();
            $onboardingEmployeesData = OnboardingEmployees::where('id', $request->user_id)->first();
                if (empty($request->custom_fields)) {
                    $customFields = json_decode($onboardingEmployeesData->custom_fields, true);
                    if (!empty($customFields) && isset($customFields[0]['placeholders'])) {
                        foreach ($customFields[0]['placeholders'] as $key => $value) {
                            $customFields[0]['placeholders'][$key] = $value;
                        }
                    }
                    $request->custom_fields = $customFields;
                }
            if($employeeIdSettingData && $employeeIdSettingData->require_approval_status == 1){
                if($onboardingEmployeesData && in_array($onboardingEmployeesData->status_id, [8,19,20])){
                    
                    $onboardingEmployeesData->hiring_signature = $request->name ?? '';
                    $onboardingEmployeesData->custom_fields = isset($request->custom_fields) ? json_encode($request->custom_fields) : null;
                    $onboardingEmployeesData->status_id = 17;
                    $onboardingEmployeesData->save();

                    $message = "Offer Review";
                    $status_code = 200;
                    $status = true;

                    if(isset($request->custom_fields)){
                        foreach ($request->custom_fields as $customField) {
                            if (!empty($customField) && isset($customField['id'], $customField['category_id'], $customField['template_name'])) {
                    
                                $existingDocument = NewSequiDocsDocument::where([
                                    'template_id' => $customField['id'],
                                    'category_id' => $customField['category_id'],
                                    'user_id'=>$request->user_id
                                ])->first();
                                    
                                if ($existingDocument) {
                                    $existingDocument->update([
                                        'smart_text_template_fied_keyval' => json_encode($customField),
                                        'updated_at' => now() 
                                } else {
                                    $template = NewSequiDocsTemplate::where('id', $customField['id'])->first();
                                    NewSequiDocsDocument::create([
                                        'user_id' => $request->user_id,
                                        'user_id_from' => 'onboarding_employees',
                                        'description' => $template ? $template->template_name : '',
                                        'is_active' => 1,
                                        'smart_text_template_fied_keyval' => json_encode($customField),
                                        'template_id' => $customField['id'],
                                        'category_id' => $customField['category_id'],
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]);
                                }
                            }
                        } 
                    }

                    return response()->json([
                        'ApiName' => $ApiName,
                        'status' => $status,
                        'message' => $message,
                    ], $status_code);
                }
            }

            DB::beginTransaction();
            
            $signing_screeen_url = isset($request->signing_screeen_url) ? $request->signing_screeen_url : '';
            $Onboarding_user_id = $request->user_id;
            $category_id = 1;
            
            $request_type = isset($request->type) && $request->type == 'resend' ? 'Resend' : 'Send';
            $send_documents_to_user = isset($request->documents) && $request->documents != 'all' ? 'Offer Letter' : 'All' ;
            
            $is_document_resend = $request_type == 'Resend' ? 1 : 0;
            
            $employeeIdSetting = EmployeeIdSetting::first();
            
            if($api_call_for == 'use'){
                $new_sequi_docs_signature_request_logs = new NewSequiDocsSignatureRequestLog();
                $new_sequi_docs_signature_request_logs->ApiName = $ApiName;
                $new_sequi_docs_signature_request_logs->save();
            }

            if(isset($new_sequi_docs_signature_request_logs->id)){
                $new_sequi_docs_signature_request_logs->user_array = $all_request_data;
                $new_sequi_docs_signature_request_logs->save();
            }
            OnboardingEmployees::where('id', $Onboarding_user_id)->update([
                'hiring_signature' => $all_request_data['name']??''
            ]);
            $Onboarding_Employees_query = OnboardingEmployees::where('id' , $Onboarding_user_id);
            
            $Onboarding_Employees_count = $Onboarding_Employees_query->count();
            $Onboarding_Employee_data_row = $Onboarding_Employees_query->first();
            if($Onboarding_Employees_count != 0){

                if($Onboarding_Employee_data_row->status_id == 5){
                    
                    $offerExpiryDate = Carbon::parse($Onboarding_Employee_data_row->offer_expiry_date);
                    $tomorrow = Carbon::tomorrow();

                    if ($offerExpiryDate->lessThan($tomorrow)) {
                        $message = 'Offer Expiry Date should be in the future';
                        return response()->json([
                            'ApiName' => $ApiName,
                            'status' => $status,
                            'message' => $message,
                        ], $status_code);
                    }


                }

                $message = "Offer letter not created for selected postion or offer letter deleted";

                $Onboarding_Employees_data = $Onboarding_Employees_query->get();
                $Onboarding_Employees_data_array = $Onboarding_Employees_data->toArray();
                $sub_position_id_array = array_column($Onboarding_Employees_data_array , 'sub_position_id');

                $newSequiDocsTemplatePermissionQuery = NewSequiDocsTemplatePermission::with('positionDetail:id,position_name')
                ->with('NewSequiDocsTemplate:id,is_deleted,template_name,template_description')
                ->whereIn('position_id',$sub_position_id_array)
                ->wherehas('NewSequiDocsTemplate')
                ->where('position_type', 'receipient')
                ->where('category_id' , $category_id);

                if($is_document_resend == 1){
                    $sentOfferLetter = SentOfferLetter::where('onboarding_employee_id',$Onboarding_Employee_data_row->id)->first();
                    if($sentOfferLetter){
                        $newSequiDocsTemplatePermissionQuery->where('template_id', $sentOfferLetter->template_id);
                    }
                } else {
                    if($request->template_id){
                        $newSequiDocsTemplatePermissionQuery->when($request->has('template_id'), function ($query) use ($request) {
                            $query->where('template_id', $request->template_id);
                        });
                    }
                }

                $receipient_postion_template_id = $newSequiDocsTemplatePermissionQuery->get()->toArray();

                $offer_letter_templates = [];

                foreach($receipient_postion_template_id as $receipient_postion_template_row){
                    $template_id = $receipient_postion_template_row['template_id'];
                    $receipient_position_name = $receipient_postion_template_row['position_detail']['position_name'];
                    $SequiDocsTemplate = NewSequiDocsTemplate::with(['permission', 'receipient','categories','document_for_send_with_offer_letter'])->orderBy('id', 'asc')->where('id' , $template_id)->first();
                    
                    if($SequiDocsTemplate != null && !empty($SequiDocsTemplate) ){
                        $offer_letter_templates[$receipient_position_name] = $SequiDocsTemplate;
                    }
                }

                if(count($offer_letter_templates) > 0){
                    $auth_user_data = Auth::user();
                    $Company_Profile_data = CompanyProfile::first();
                    $company_data_reslove_key = NewSequiDocsTemplate::company_data_reslove_key($Company_Profile_data ,$auth_user_data);
                    $stored_bucket = $this->stored_bucket;

                    $pdf_send_count = 0;
                    foreach($Onboarding_Employees_data as $user_index => $users_row){
                        
                        $position_name = $users_row['positionDetail']['position_name'];
                        $user_email = $users_row['email'];
                        $offer_expiry_date = $users_row['offer_expiry_date'];
                        // $user_email = 'santuram.sahu@silvergrey.in';

                        $email_arr = [];
                        $signer_user = [
                            // "user_id" => $users_row['id'],
                            "email" => $user_email,
                            "user_name" => $users_row['first_name']." ".$users_row['last_name'],
                            'role' => 'employee'
                        ];

                        $response = [
                            "id" => $users_row['id'],
                            "user_ame" => $users_row['first_name']." ".$users_row['last_name'],
                            "position_name" => $position_name,
                            "message" => "Template not created for ".$position_name,
                            "status" => false
                        ];

                        if (array_key_exists($position_name,$offer_letter_templates))
                        {
                            $Sequi_Docs_Template_data = $offer_letter_templates[$position_name];
                            
                            $is_template_ready = $Sequi_Docs_Template_data->is_template_ready;
                            $category_id = $Sequi_Docs_Template_data->category_id;
                            $completed_step = $Sequi_Docs_Template_data->completed_step;
                            $message = "offer letter is not ready for send!! can't send it";
                            $response['message'] = $message;

                            if($category_id == 1 && ($completed_step == 4 || $is_template_ready == 1)){
                                $domain_setting = false;
                                $domain_error_on_email = [];
                                $message = "Domain setting isn't allowed to send e-mail on this domain.";
                                $response['message'] = $message;

                                $email_arr[] = $signer_user; 
                                $final_email_array_for_send_mail = [];  
                                unset($final_email_array_for_send_mail);  
                                foreach($email_arr as $email_row){
                                    $email = $email_row['email'];
                                    $emailId = explode("@",$email);
                                    $user_email_for_send_email = $email;
                                    $check_domain_setting = DomainSetting::check_domain_setting($user_email_for_send_email);
                                    if($check_domain_setting['status'] == true){
                                        $final_email_array_for_send_mail[] = $email_row;
                                        $domain_setting = true;
                                    }else{
                                        array_push($domain_error_on_email, $email);
                                    }
                                }


                                $send_document_final_array = [];
                                if($domain_setting){
                                    $send_document_array = [
                                        'status' => false,
                                        'dcument_other_details' => [],
                                        'pdf_detail_arr' => [],
                                    ];

                                    $Sequi_Docs_Template_data;
                                    $template_name = ucwords($Sequi_Docs_Template_data->template_name);
                                    $template_description = $Sequi_Docs_Template_data->template_description;
                                    $template_content = $Sequi_Docs_Template_data->template_content;
                                    $is_pdf = $Sequi_Docs_Template_data->is_pdf;


                                    $send_reminder = $Sequi_Docs_Template_data->send_reminder;
                                    $reminder_in_days = $Sequi_Docs_Template_data->reminder_in_days;
                                    $reminder_in_days = $Sequi_Docs_Template_data->reminder_in_days;
                                    $max_reminder_times = $Sequi_Docs_Template_data->max_reminder_times;
                                    $is_sign_required_for_hire = $Sequi_Docs_Template_data->recipient_sign_req;

                                    $email_subject = $Sequi_Docs_Template_data->email_subject;
                                    $email_content = $Sequi_Docs_Template_data->email_content;
                                    $pdf_file_other_parameter = $Sequi_Docs_Template_data->pdf_file_other_parameter;
                                    
                                    $categories = $Sequi_Docs_Template_data->categories;
                                    $to_send_template_id = $Sequi_Docs_Template_data->id;

                                    $category_array = [];
                                    $category_array['id'] = $categories->id;
                                    $category_array['categories'] = $categories->categories;
                                    $category_array['category_type'] = $categories->category_type;
                                    $pdf_detail_arr = [
                                        "pdf_path" => "",
                                        "is_pdf" => 0,
                                        "pdf_file_other_parameter" => $pdf_file_other_parameter,
                                        "is_sign_required_for_hire" => $is_sign_required_for_hire,
                                        "template_name" => $template_name,
                                        "offer_expiry_date" => $offer_expiry_date,
                                        "is_post_hiring_document" => 0,
                                        "is_document_for_upload" => 0,
                                        "category_id"=>$category_array['id'],
                                        "category"=>$category_array['categories'],
                                        "category_type"=>$category_array['category_type'],
                                        "upload_by_user" => 0,
                                        "signer_array" => []
                                    ]; 
                                    $dcument_other_details = [
                                        "template_id" => $to_send_template_id,
                                        "send_reminder" => $send_reminder,
                                        "offer_expiry_date" => $offer_expiry_date,
                                        "reminder_in_days" => $reminder_in_days,
                                        "max_reminder_times" => $max_reminder_times,
                                        "is_sign_required_for_hire" => $is_sign_required_for_hire,
                                        "is_document_for_upload" => 0,
                                        'category_array' => $category_array
                                    ];
                                    $other_required_data = [
                                        "users_row" => $users_row ,
                                        "offer_expiry_date" => $offer_expiry_date,
                                        "auth_user_data" => $auth_user_data,
                                        "signer_array" => $final_email_array_for_send_mail,
                                        "Company_Profile_data" => $Company_Profile_data,
                                        "api_call_for" => $api_call_for,
                                        "custom_fields" => $request->custom_fields ?? []
                                    ];
                                    $document_for_send_with_offer_letter = $Sequi_Docs_Template_data->document_for_send_with_offer_letter;
                                    $send_mail_is_true = false;

                                    $message = "something went wrong!!! Template pdf not found for send";
                                    $response['message'] = $message;

                                    $Document_Type = $Sequi_Docs_Template_data->categories->categories;
                                    $Document_Type = rtrim($Document_Type, 's');
                                    $html = (isset($template_content)) ? $template_content : null;
                                    $user_data_reslove_key = NewSequiDocsTemplate::user_data_reslove_key($users_row ,$auth_user_data);

                                    $template_name_is = isset($template_name)?str_replace(' ', '_', $template_name): 'Template';
                                    $string = NewSequiDocsTemplate::resolve_documents_content($html , $users_row , $auth_user_data , $Company_Profile_data);
                                    $generateTemplate = $template_name_is. '_' . date('m_d_Y')."_".time().'.pdf';
                                    $template_document_is = "template/".$generateTemplate;

                                    
                                    $pdf = Pdf::loadHTML($string, 'UTF-8');
                                    $filePath = env('DOMAIN_NAME').'/'.$template_document_is;
                                    $file_link = $serverIP."/".$template_document_is;

                                    $s3_return = s3_upload($filePath , $pdf->setPaper('A4','portrait')->output(), false , $stored_bucket);
                                    if(isset($s3_return['status']) && $s3_return['status'] == true){
                                        $file_link =  $s3_return['ObjectURL'];
                                        $send_mail_is_true = true;
                                    }
                                    
                                    if($send_mail_is_true == true){
                                        $send_document_array['status'] = true;
                                        $pdf_detail_arr['pdf_path'] = $file_link;
                                        $pdf_detail_arr['signer_array'] = $final_email_array_for_send_mail;

                                        $send_document_array['dcument_other_details'] = $dcument_other_details;
                                        $send_document_array['pdf_detail_arr'] = $pdf_detail_arr;

                                        if(($send_documents_to_user == 'All' && $is_document_resend == 1) || $is_document_resend == 0){
                                            $send_document_with_offer_letter_response = $this->send_document_with_offer_letter($document_for_send_with_offer_letter , $other_required_data);
                                        }else{
                                            $send_document_with_offer_letter_response = ["response" => [], 'status_code' =>200];
                                        }

                                        if($send_document_with_offer_letter_response['status_code'] == 200){
                                            $response_data = $send_document_with_offer_letter_response['response'];
    
                                            if(count($response_data) > 0){
                                                array_unshift($response_data , $send_document_array);
                                                $send_document_final_array = $response_data;
                                            }else{
                                                $send_document_final_array[] = $send_document_array;
                                            }
                                        }else{

                                            $message = "something went wrong!!";
                                            $error = $send_document_with_offer_letter_response['error'];
                                            $errorDetail = $send_document_with_offer_letter_response['errorDetail'];
                                            return response()->json(['message' => $message, 'error' => $error,  'errorDetail' => $errorDetail ], 400);
                                        }

                                        if($api_call_for != 'test' && $is_document_resend == 0){
                                            $envelope_data = $this->createEnvelope();
                                            if(isset($new_sequi_docs_signature_request_logs->id)){
                                                $new_sequi_docs_signature_request_logs->envelope_data = $envelope_data;
                                                $new_sequi_docs_signature_request_logs->save();
                                            }
                                        } else if($api_call_for != 'test' &&$is_document_resend == 1)
                                        {
                                            $user_old_document =  NewSequiDocsDocument::where('user_id',$users_row['id'])->where('category_id', $category_id)->where('user_id_from','onboarding_employees')->where('is_active',1)
                                            ->first();


                                            if($user_old_document){

                                                $envelope_data = Envelope::find($user_old_document->envelope_id);
                                                if(!$envelope_data && $user_old_document->imported_from_old == 1){
                                                    $envelope_data = $this->createEnvelope();
    
    
                                                }
    
                                                if(isset($new_sequi_docs_signature_request_logs->id)){
                                                    $new_sequi_docs_signature_request_logs->envelope_data = $envelope_data;
                                                    $new_sequi_docs_signature_request_logs->save();
                                                }

                                            } 

                                        }

                                        $message = "signature request not send!!";
                                        $response['message'] = $message;

                                        $Document_list_is = "";
                                        $allow_mail_send = false;

                                        if(isset($new_sequi_docs_signature_request_logs->id)){
                                            $new_sequi_docs_signature_request_logs->send_document_final_array = $send_document_final_array;
                                            $new_sequi_docs_signature_request_logs->save();
                                        }
                                        $signature_request_response = [];
                                        $false_signature_request_response = [];
                                        foreach($send_document_final_array as $send_document_row){
                                            if($send_document_row['status'] == true){
                                                $dcument_other_details = $send_document_row['dcument_other_details'];
                                                $pdf_detail_arr = $send_document_row['pdf_detail_arr'];
                                                $pdf_detail_arr['categories'] = $dcument_other_details['category_array'];

                                                $template_id = $dcument_other_details['template_id'];
                                                $send_reminder = $dcument_other_details['send_reminder'];
                                                $reminder_in_days = $dcument_other_details['reminder_in_days'];
                                                $max_reminder_times = $dcument_other_details['max_reminder_times'];
                                                $is_sign_required_for_hire = $dcument_other_details['is_sign_required_for_hire'];
                                                $categories_array = $dcument_other_details['category_array'];

                                                $category_id = isset($categories_array['id']) ? $categories_array['id'] : null;

                                                $template_name = $pdf_detail_arr['template_name'];
                                                $is_post_hiring_document = $pdf_detail_arr['is_post_hiring_document'];
                                                $pdf_path = $pdf_detail_arr['pdf_path'];

                                                $is_document_for_upload = $pdf_detail_arr['is_document_for_upload'];
                                                $upload_by_user = $pdf_detail_arr['upload_by_user'];

                                                $document_uploaded_type = "secui_doc_uploaded";
                                                $upload_document_type_id = null;
                                                if($is_document_for_upload == 1){
                                                    $document_uploaded_type = "manual_doc";
                                                    $manualDocType = NewSequiDocsUploadDocumentType::where([
                                                        'is_deleted' => 0,
                                                        'document_name' => $pdf_detail_arr['template_name']
                                                    ])->first();

                                                    if($manualDocType){
                                                        $upload_document_type_id = $manualDocType->id;
                                                    }
                                                    
                                                }

                                                if($signing_screeen_url == ''){
                                                    $signing_screeen_url = $pdf_path;
                                                }

                                                if($api_call_for != 'test'){

                                                    $response['message'] = "signature request Envelop not created!! signature request not send!!";
                                                    if(!empty($envelope_data) && $envelope_data != null){
                                                        
                                                        $envelope_id = $envelope_data->id;
                                                        $envelope_name = $envelope_data->envelope_name;
                                                        $envelope_password = $Document_Access_Password = $envelope_data->plain_password;

                                                        $signing_screeen_url = config('signserver.signScreenUrl') .'/'. $Document_Access_Password;
                                                        $Review_Document_Link =$signing_screeen_url;
                                                        if($send_documents_to_user != 'All' && $is_document_resend == 1){

                                                            $user_old_document =  NewSequiDocsDocument::where('user_id',$users_row['id'])->where('category_id', $category_id)->where('user_id_from','onboarding_employees')->where('is_active',1)
                                                            ->first();


                                                            if($user_old_document != null && $user_old_document != ''){
                                                                if($user_old_document->imported_from_old == 0){

                                                                    $envelope_id = $user_old_document->envelope_id;
                                                                    $envelope_password = $Document_Access_Password = $user_old_document->envelope_password;

                                                                } 
                                                            }
                                                        }

                                                        if($is_document_for_upload == 0){
                                                            $addDocumentsInToEnvelope =  $this->addDocumentsInToEnvelope($envelope_id,[$pdf_detail_arr]);
                                                        }else{
                                                            $addDocumentsInToEnvelope = ["status" =>true, "is_document_for_upload" => $is_document_for_upload];
                                                        }

                                                        $signature_request_response[] = ["template_name" => $template_name,"pdf_detail_arr" => [$pdf_detail_arr], "addDocumentsInToEnvelope" =>$addDocumentsInToEnvelope];

                                                        if(isset($new_sequi_docs_signature_request_logs->id)){
                                                            $new_sequi_docs_signature_request_logs->signature_request_response = $signature_request_response;
                                                            $new_sequi_docs_signature_request_logs->save();
                                                        }

                                                        if($addDocumentsInToEnvelope['status'] == false){
                                                            $false_signature_request_response[] = [
                                                                "pdf_detail_arr" => [$pdf_detail_arr],
                                                                "addDocumentsInToEnvelope" => $addDocumentsInToEnvelope
                                                            ];
                                                            $response['false_signature_request_response'] = $false_signature_request_response;
                                                            
                                                        }
            
                                                        if((isset($addDocumentsInToEnvelope['status']) && $addDocumentsInToEnvelope['status']  == true) || $is_document_for_upload == 1){



                                                            $signature_request_id = isset($addDocumentsInToEnvelope['signature_request_id']) ? $addDocumentsInToEnvelope['signature_request_id'] : null;
                                                            $documnet = isset($addDocumentsInToEnvelope['documnet']) ? $addDocumentsInToEnvelope['documnet'] : null;
                                                            $signature_request_document_id = isset($documnet[0]['signature_request_document_id']) ? $documnet[0]['signature_request_document_id'] : null;
                                                            NewSequiDocsDocument::where('user_id',$users_row['id'])->where('document_uploaded_type',$document_uploaded_type)->where('user_id_from','onboarding_employees')->where('is_active',1)
                                                            ->where(function ($query) use ($template_id , $category_id){
                                                                $query->where(function ($query) use ($template_id , $category_id){
                                                                    $query->where('template_id', $template_id)
                                                                        ->where('category_id', $category_id)
                                                                        ->whereNull('upload_document_type_id');
                                                                })
                                                                ->orWhere(function ($query) use ($template_id){
                                                                    $query->where('upload_document_type_id', $template_id)
                                                                        ->where(function ($query) {
                                                                            $query->where('category_id', 0)
                                                                                ->orWhereNull('category_id');
                                                                        });
                                                                });
                                                            })
                                                            ->update(['is_active' => 0, 'document_inactive_date' => NOW()]);

                                                        $customField = NewSequiDocsDocument::where(['user_id'=> $request->user_id, 'category_id'=> 101, 'user_id_from'=> 'onboarding_employees', 'description'=> 'Smart Text Template'])->first();
                                                        if(empty($request->custom_fields) && $category_id == 101 && !empty($customField)) {

                                                        }else{
                                                            
                                                            $signed_status = 0;
                                                            $create_NewSequiDocsDocument = new NewSequiDocsDocument();
                                                            
                
                                                            $create_NewSequiDocsDocument->user_id = $users_row['id'];
                                                            $create_NewSequiDocsDocument->user_id_from = 'onboarding_employees';
                                                            $create_NewSequiDocsDocument->template_id = $template_id;
                                                            $create_NewSequiDocsDocument->category_id = $category_id;
                                                            $create_NewSequiDocsDocument->description = $template_name;
                                                            $create_NewSequiDocsDocument->is_active = 1;
                                                            $create_NewSequiDocsDocument->send_by = $auth_user_data['id'];
                                                            $create_NewSequiDocsDocument->upload_document_type_id = $upload_document_type_id;
                                                            $create_NewSequiDocsDocument->is_document_resend = $is_document_resend;
                                                            
                                                            
                                                            $create_NewSequiDocsDocument->un_signed_document = $pdf_path;
                                                            $create_NewSequiDocsDocument->document_send_date = now();
                                                            $create_NewSequiDocsDocument->document_response_status = 0;
                                                            $create_NewSequiDocsDocument->document_uploaded_type = $document_uploaded_type;
                                                            
                                                            $create_NewSequiDocsDocument->envelope_id = $envelope_id;
                                                            $create_NewSequiDocsDocument->envelope_password = $envelope_password;
                                                            $create_NewSequiDocsDocument->signature_request_id = $signature_request_id;
                                                            $create_NewSequiDocsDocument->signature_request_document_id = $signature_request_document_id;
                                                            $create_NewSequiDocsDocument->signed_status = $signed_status;
                                                            $create_NewSequiDocsDocument->is_post_hiring_document = $is_post_hiring_document;
                                                            $create_NewSequiDocsDocument->is_sign_required_for_hire = $is_sign_required_for_hire;

                
                                                            // reminder data is_post_hiring_document
                                                            $create_NewSequiDocsDocument->send_reminder = $send_reminder;
                                                            $create_NewSequiDocsDocument->reminder_in_days = $reminder_in_days;
                                                            $create_NewSequiDocsDocument->max_reminder_times = $max_reminder_times;
                                                            $is_new_document_created = $create_NewSequiDocsDocument->save();
                                                        }
                                                            if($request->custom_fields){
                                                                foreach($request->custom_fields as $custom_fields){
                                                                    if($request->custom_fields && $category_id == 101){
    
                                                                        NewSequiDocsDocument::where(
                                                                            [
                                                                                'id' => $create_NewSequiDocsDocument->id, 
                                                                                'description' => $custom_fields['template_name']
                                                                            ]
                                                                        )->update([
                                                                            'smart_text_template_fied_keyval' => json_encode($custom_fields), 
                                                                        ]);
                                                                    }
                                                                }
                                                                
                                                            }
                                                            
                                                            $message = "something went wrong!! Document not saved";
                                                            if($employeeIdSetting->require_approval_status == 1 && in_array($Onboarding_Employee_data_row->status_id, [8,19,20])){
                                                                $message = "Offer Review";
                                                                $status_code = 200;
                                                                $status = true;
                                                                $allow_mail_send = false;
                                                                $update_OnboardingEmployees =  OnboardingEmployees::find($users_row['id']);
                                                                $update_OnboardingEmployees->status_id = 17;
                                                                $update_OnboardingEmployees->custom_fields = isset($request->custom_fields) 
                                                                ? json_encode($request->custom_fields) 
                                                                : ($update_OnboardingEmployees->custom_fields ?? null);

                                                                $update_OnboardingEmployees->save();

                                                            }else{
                                                               
                                                                if($category_id == 1){
                                                                    $allow_mail_send = true;
                                                                    $status_id = $is_document_resend == 1 ? 12 : 4 ;
                                                                    $update_OnboardingEmployees =  OnboardingEmployees::find($users_row['id']);
                                                                    
                                                                    $update_OnboardingEmployees->status_id = $status_id;
                                                                    $update_OnboardingEmployees->document_id = $signature_request_document_id;
                                                                    $update_OnboardingEmployees->custom_fields = isset($request->custom_fields) 
                                                                    ? json_encode($request->custom_fields) 
                                                                    : ($update_OnboardingEmployees->custom_fields ?? null);

                                                                    $update_OnboardingEmployees->save();
                                                                }
                                                            }
                                                            
                                                            if($is_new_document_created && $is_post_hiring_document != 1){
                                                                if($is_sign_required_for_hire == 1){
                                                                    
                                                                    $Document_list_is .= "<li>".$template_name."<span style=\"color:red\"> * </span></li>";

                                                                } else {
                                                                    $Document_list_is .= "<li>".$template_name."</li>";
                                                                }
                                                                
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        if($employeeIdSetting->require_approval_status == 1 && in_array($Onboarding_Employee_data_row->status_id, [8,19,20])){
                                            $update_OnboardingEmployees =  OnboardingEmployees::find($users_row['id']);
                                            $update_OnboardingEmployees->status_id = 17;
                                            $update_OnboardingEmployees->save();

                                        }else{

                                            $sendOfferLetterStatus = HiringStatus::where('id', 4)->first();
                                            $onboardingEmployeesStatus =  OnboardingEmployees::find($users_row['id']);
                                            if($sendOfferLetterStatus!=null && $onboardingEmployeesStatus!=null){
                                            $onboardingEmployeesStatus->status_id = $sendOfferLetterStatus->id ?? $onboardingEmployeesStatus->status_id;
                                            $onboardingEmployeesStatus->save();
                                        }

                                        }
                                        if($allow_mail_send == true){
                                            foreach($final_email_array_for_send_mail as $signer){
                                                $email_template_data['email'] = $signer['email'];
                                                $Review_Document_Link = $signing_screeen_url;
                                                
                                                $document_email_format = NewSequiDocsTemplate::resolve_email_content($email_content , $users_row , $auth_user_data , $Company_Profile_data);
                                                $document_email_format = str_replace("[Review_Document_Link]", $Review_Document_Link, $document_email_format);
                                                $document_email_format = str_replace("[Document_list_is]", $Document_list_is, $document_email_format);
                                                $document_email_format = str_replace("[Document_Access_Password]", $Document_Access_Password, $document_email_format);
                        
                                                if($is_document_resend == 1){
                                                    $document_email_format = str_replace("has sent an Offer", "has re-sent an Offer", $document_email_format);
                                                }

                                                $email_template_data['subject'] = $email_subject;
                                                $email_template_data['template'] = $document_email_format;
                                                $email_response = $this->sendEmailNotification($email_template_data);
                                                if ($users_row['is_background_verificaton'] == 1) {
                                                    $configurationDetails = SClearanceConfiguration::where('position_id', $users_row['position_id'])->where('hiring_status', 1)->orWhere('position_id', $users_row['sub_position_id'])->first();
                                                    if (empty($configurationDetails)) {
                                                        $configurationDetails = SClearanceConfiguration::where(['position_id' => null])->first();
                                                    }
                                                    if (!empty($configurationDetails)) {
                                                        if ($configurationDetails->hiring_status == 1) {
                                                            $parsedUrl = parse_url($request->signing_screeen_url);
                                                            $frontendUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                                                            $screeningRequest = SClearanceTurnScreeningRequestList::where(['email' => $users_row['email']])->first();
                                                            if (!$screeningRequest) {
                                                                $package_id = $configurationDetails->package_id;
                                                                $srRequestSave = SClearanceTurnScreeningRequestList::create([
                                                                    'email' => $users_row['email'],
                                                                    'user_type' => 'Onboarding',
                                                                    'user_type_id' => $users_row['id'],
                                                                    'position_id' => $users_row['position_id'],
                                                                    'office_id' => $users_row['office_id'],
                                                                    'first_name' => $users_row['first_name'],
                                                                    'middle_name' => @$users_row['middle_name'],
                                                                    'last_name' => $users_row['last_name'],
                                                                    'package_id' => $package_id,
                                                                    'description' => "Background Check",
                                                                    'status' => 'emailed' 
                                                                ]);
                                                                $srRequestSave->save();
                                                                $request_id = $srRequestSave->id;
                                                            } else {
                                                                $request_id = $screeningRequest->id;
                                                            }

                                                            $mailData['subject'] = 'Request for Background Check';
                                                            $mailData['email'] =  $users_row['email'];
                                                            $mailData['request_id'] =  $request_id;
                                                            $encryptedRequestId = encryptData($request_id);
                                                            $mailData['encrypted_request_id'] = $encryptedRequestId;
                                                            $mailData['url'] = $frontendUrl;
                                                            $mailData['template'] = view('mail.backgroundCheckMail', compact('mailData'));
                                                            $this->sendEmailNotification($mailData);
                                                        }
                                                    }
                                                }

                                                $response['email_response'] = $email_response;
                                                $response['Document_Access_Password'] = $Document_Access_Password;

                                                if(gettype($email_response) == 'string'){
                                                    $email_response = json_decode($email_response,true);
                                                }
                
                                                if(gettype($email_response) == 'array' && isset($email_response['errors'])){
                                                    $response['message'] = $email_response['errors'][0];
                                                }else{
                                                    $status_code = 200;
                                                    $status = true;
                                                    $response['status'] = true;
                                                    $pdf_send_count++;
                                                    $message = "pdf send in Mail";
                                                    $response['message']  = $message;
                                                }

                                                /************  hubspot code starts here **************** */
                                                if(isset($Onboarding_Employees_data_array[0]['id'])){

                                                    $OnboardingEmployees_status = 'Offer Letter sent';
                                                    $onboardingEmpID = $Onboarding_Employees_data_array[0]['id'];
                                                    $OnboardingEmployees =  OnboardingEmployees::find($onboardingEmpID);
                                                    $userId = Auth()->user();
                                                    $recruiter_id = ($userId->is_super_admin==0)? $userId->id : null;
                                                    $CrmData = Crms::where('id',2)->where('status',1)->first();
                                                    $CrmSetting = CrmSetting::where('crm_id',2)->first();
                                                    if(!empty($CrmData) && !empty($CrmSetting)){
                                                        $val = json_decode($CrmSetting['value']);
                                                        $token = $val->api_key;
                                                        $OnboardingEmployees->status = $OnboardingEmployees_status;
                                                        $hubspotSaleDataCreate = $this->hubspotOnboardemployee($OnboardingEmployees,$recruiter_id,$token);
                                                    }

                                                }    
                                            }
                                        }
                                    }
                                }else{
                                    $errorDetails = array(
                                        'message' => "The email couldn't be sent due to Domain settings not allowing to send email, Please check the domain configurations and try to send email again.",
                                        'domain_name' => '',
                                        'recipient_email' => $domain_error_on_email
                                    );
                                    $this->sendEmailErrorNotificationToSA($errorDetails, 'domain_error');
                                }
                            }
                        }
                        $response_array[$user_index] = $response;
                    }
                }

            }


            DB::commit();
        } catch (Exception $error) {
            Log::debug($error);
            $message = "something went wrong!!";
            $error_message = $error->getMessage();
            $File  = $error->getFile();
            $Line  = $error->getLine();
            $Code  = $error->getCode();
            $Trace  = $error->getTraceAsString();
            $errorDetail = [
                "error_message" => $error_message,
                "File" => $File,
                "Line" => $Line,
                "Code" => $Code,
            ];
            sequiDocsErrorNotification($error);
            DB::rollBack();
            return response()->json(['error' => $error, 'message' => $message, 'errorDetail' => $errorDetail ], 400);
        }

        return response()->json([
            'ApiName' => $ApiName,
            'status' => $status,
            'message' => $message,
            'response_array' => $response_array,
            "other_data" => [
                'pdf_send_count' => $pdf_send_count,
                'Document_list_is' => $Document_list_is,
            ],
        ], $status_code);
    }








/*
=============================================== update onboarding employee hired_employee =============================================== 
*/








public function UpdateOnboardingEmployee(Request $request)
    {
        $workerId = '';
        if (!$request->image == NULL) {
            $file = $request->file('image');
            if (isset($file) && $file != null && $file != '') {
                $img_path = time() . $file->getClientOriginalName();
                $img_path = str_replace(' ', '_', $img_path);
                $awsPath = env('DOMAIN_NAME') . '/' . 'Employee_profile/' . $img_path;
                s3_upload($awsPath, file_get_contents($file), false);
                $image_path = time() . $file->getClientOriginalName();
                $ex = $file->getClientOriginalExtension();
                $destinationPath = 'Employee_profile';
                $image_path = $file->move($destinationPath, $img_path);
            }
        } else {
            $image_path = 'Employee_profile/default-user.png';
        }

        $uid = auth()->user()->id;
        $empUpdate = User::find($uid);

        if (env("DOMAIN_NAME") == 'newera') {
            $getUserdiisplayName = User::where("first_name", trim($empUpdate->first_name))
                ->where("last_name", trim($empUpdate->last_name))->where('id', '!=', $uid)->first();
            if ($getUserdiisplayName != "") {
                return response()->json([
                    'ApiName' => 'update-onboarding_employee',
                    'status' => false,
                    'message' => 'This display name: ' . trim($empUpdate->first_name) . ' ' . trim($empUpdate->last_name) . ' is already exist in Users List ',
                ], 400);
            }
            $getOnboardingEmployeesdiisplayName = OnboardingEmployees::where("first_name", trim($empUpdate->first_name))
                ->where("last_name", trim($empUpdate->last_name))->where('user_id', '!=', $uid)->first();
            if ($getOnboardingEmployeesdiisplayName != "") {
                return response()->json([
                    'ApiName' => 'update-onboarding_employee',
                    'status' => false,
                    'message' => 'This display name: ' . trim($empUpdate->first_name) . ' ' . trim($empUpdate->last_name) . ' is already exist in onboarding List ',
                ], 400);
            }
        }

        $aveyoid = auth()->user()->aveyo_hs_id;

        $empUpdate->home_address = $request['home_address'];
        $empUpdate->first_name = $request->first_name;
        $empUpdate->last_name = $request->last_name;
        $empUpdate->dob = $request->birth_date;
        $empUpdate->emergency_contact_name = $request->emergency_contact_name;
        $empUpdate->emergency_phone = $request->emergency_phone;
        $empUpdate->emergency_contact_relationship = $request->emergency_contact_relationship;
        $empUpdate->emergrncy_contact_address = $request->emergrncy_contact_address;
        $empUpdate->emergrncy_contact_city = $request->emergrncy_contact_city;
        $empUpdate->emergrncy_contact_state = $request->emergrncy_contact_state;
        $empUpdate->emergrncy_contact_zip_code = $request->emergrncy_contact_zip_code;

        if($empUpdate->worker_type == '1099'){

            $empUpdate->social_sequrity_no = isset($request->social_sequrity_no) ? $request->social_sequrity_no : '';
            $empUpdate->tax_information = isset($request->tax_information) ? $request->tax_information : '';
            $empUpdate->name_of_bank = isset($request->name_of_bank) ? $request->name_of_bank : '';
            $empUpdate->routing_no = isset($request->routing_no) ? $request->routing_no : '';
            $empUpdate->account_no = isset($request->account_no) ? $request->account_no : '';
            $empUpdate->account_name = isset($request->account_name) ? $request->account_name : '';
            $empUpdate->confirm_account_no = isset($request->confirm_account_no) ? $request->confirm_account_no : '';
            $empUpdate->type_of_account = isset($request->type_of_account) ? $request->type_of_account : '';
            $empUpdate->entity_type = isset($request->entity_type) ? $request->entity_type : '';
            $empUpdate->business_name = isset($request->business_name) ? $request->business_name : '';
            $empUpdate->business_type = isset($request->business_type) ? $request->business_type : '';
            $empUpdate->business_ein = isset($request->business_ein) ? $request->business_ein : '';

        }
        $empUpdate->shirt_size = isset($request->shirt_size) ? $request->shirt_size : '';
        $empUpdate->hat_size = isset($request->hat_size) ? $request->hat_size : '';
        $empUpdate->sex = isset($request->gender) ? $request->gender : '';
        $empUpdate->image = isset($image_path) ? $image_path : '';
        $empUpdate->onboardProcess = $request->onboardProcess;
        $empUpdate->employee_additional_fields = $request->employee_additional_fields;
        $empUpdate->employee_personal_detail = $request->employee_personal_detail;
        $empUpdate->additional_info_for_employee_to_get_started = $request->additional_info_for_employee_to_get_started;
        
        $empUpdate->mobile_no = isset($request->mobile_no) ? $request->mobile_no : '';

        $empUpdate->home_address_line_1 = isset($request['home_address_line_1']) ? $request['home_address_line_1'] : $empUpdate->home_address_line_1;
        $empUpdate->home_address_line_2 = isset($request['home_address_line_2']) ? $request['home_address_line_2'] : $empUpdate->home_address_line_2;
        $empUpdate->home_address_state = isset($request['home_address_state']) ? $request['home_address_state'] : $empUpdate->home_address_state;
        $empUpdate->home_address_city = isset($request['home_address_city']) ? $request['home_address_city'] : $empUpdate->home_address_city;
        $empUpdate->home_address_zip = isset($request['home_address_zip']) ? $request['home_address_zip'] : $empUpdate->home_address_zip;
        $empUpdate->home_address_lat = isset($request['home_address_lat']) ? $request['home_address_lat'] : $empUpdate->home_address_lat;
        $empUpdate->home_address_long = isset($request['home_address_long']) ? $request['home_address_long'] : $empUpdate->home_address_long;
        $empUpdate->home_address_timezone = isset($request['home_address_timezone']) ? $request['home_address_timezone'] : $empUpdate->home_address_timezone;
        $empUpdate->emergency_address_line_1 = isset($request['emergency_address_line_1']) ? $request['emergency_address_line_1'] : $empUpdate->emergency_address_line_1;
        $empUpdate->emergency_address_line_2 = isset($request['emergency_address_line_2']) ? $request['emergency_address_line_2'] : $empUpdate->emergency_address_line_2;
        $empUpdate->emergency_address_lat = isset($request['emergency_address_lat']) ? $request['emergency_address_lat'] : $empUpdate->emergency_address_lat;
        $empUpdate->emergency_address_long = isset($request['emergency_address_long']) ? $request['emergency_address_long'] : $empUpdate->emergency_address_long;
        $empUpdate->emergency_address_timezone = isset($request['emergency_address_timezone']) ? $request['emergency_address_timezone'] : $empUpdate->emergency_address_timezone;
        
        $empUpdate->save();
        $JobnimbusMessage = '';
        $userdata = User::with('state')->where('id', $uid)->first();

        $createComponentSessionOfWorkerIdRespose = [];
        $worker_type = '';

        if ($userdata) {
            $worker_type = strtolower($userdata->worker_type);
            $CrmData = Crms::where('id',3)->where('status',1)->first();
            if($CrmData)
            {
                if($worker_type == 'w2'){

                    $evereeEmbedOnboardingURL = '';
                    $response = $this->addEmployeeForEmbeddedOnboarding($userdata); 
                    Log::debug('Everee addEmployeeForEmbeddedOnboarding resp');
                    Log::debug($response);
                    if(isset($response['errorMessage'])){
                        return response()->json([
                            'ApiName' => 'Update Employee Detail',
                            'status' => false,
                            'message' => 'Everee Error Message:' . $response['errorMessage'],
                        ],400);
                    }
                    if(isset($response['workerId']))
                    {
                        $workerId = $response['workerId'];
                        User::where('id', $userdata->id)->update([
                            'everee_workerId' => $workerId
                        ]);

                        if($request->passEvereeOnboardingIframeUrl == 1)
                        {
                            $userdata = User::with('state')->where('id', $uid)->first();
                            $this->update_w2_emp_data($userdata, $userdata->state);
                            
                            $createComponentSessionOfWorkerIdRespose = $this->createComponentSessionOfWorkerId($workerId); 
                            Log::debug('Everee Component Session API Response');
                            Log::debug($createComponentSessionOfWorkerIdRespose);
                        }
                    }
                } 
            }
        }


        $jobNimbusCrmData = Crms::whereHas('crmSetting')->with('crmSetting')->where('id', 4)->where('status', 1)->first();
        if (!empty($jobNimbusCrmData)) {
            $decreptedValue = openssl_decrypt($jobNimbusCrmData->crmSetting->value, env('ENCRYPTION_CIPHER_ALGO'), env('ENCRYPTION_KEY'), 0, env('ENCRYPTION_IV'));
            $jobNimbusCrmSetting = json_decode($decreptedValue);
            $jobNimbusToken = $jobNimbusCrmSetting->api_key;
            $jobnimbus_jnid = auth()->user()->jobnimbus_jnid;
            $postDataToJobNimbus = array(
                'email' => $userdata->email,
                'home_phone' => $userdata->mobile_no,
                'record_type_name' => 'Subcontractor',
                'status_name' => 'Solar Reps',
                'city' => $userdata->home_address_city,
                'address_line1' => $userdata->home_address_line_1,
                'state_text' => $userdata->state->state_code,
                'external_id' => $userdata->employee_id,
            );
            if (!empty($jobnimbus_jnid) || $jobnimbus_jnid != null) {
                return $responseJobNimbuscontats = $this->updateJobNimbuscontats($postDataToJobNimbus, $jobnimbus_jnid, $jobNimbusToken);
            } else {
                $postDataToJobNimbus['display_name'] = $userdata->first_name . ' ' . $userdata->last_name;
                $postDataToJobNimbus['first_name'] = $userdata->first_name;
                $postDataToJobNimbus['last_name'] = $userdata->last_name;
                $responseJobNimbuscontats = $this->storeJobNimbuscontats($postDataToJobNimbus, $jobNimbusToken);
            }
            if ($responseJobNimbuscontats['status'] === true) {
                User::where('id', $uid)->update([
                    'jobnimbus_jnid' => $responseJobNimbuscontats['data']['jnid'],
                    'jobnimbus_number' => $responseJobNimbuscontats['data']['number']
                ]);
            } else {
                $JobnimbusMessage = ' but ' . $responseJobNimbuscontats['message'];
            }
        }

        $worker_type = strtolower($userdata->worker_type);
        if($worker_type == 'w2' && $workerId)
        {

            $workerDataFromEveree = $this->retrieveWorkerByEvereeWorkerID($workerId);
            Log::debug('Fetch data from everee and update local DB');
            Log::debug('$workerDataFromEveree');
            Log::debug($workerDataFromEveree);
            $state = State::where('state_code', $workerDataFromEveree['homeAddress']['current']['state'] ?? null)->first();
            $home_address_state = null;
            if($state)
            {
                $home_address_state = $state->state_code;
            }
            if(isset($workerDataFromEveree['unverifiedTinType']) && $workerDataFromEveree['unverifiedTinType'] == 'SSN'){
                User::where('everee_workerId',$workerId)->update([
                    'social_sequrity_no' => $workerDataFromEveree['taxpayerIdentifier'],
                    'entity_type' => 'individual'
                ]);
            }
            if(isset($workerDataFromEveree['unverifiedTinType']) && $workerDataFromEveree['unverifiedTinType'] == 'ITIN'){
                User::where('everee_workerId',$workerId)->update([
                    'business_ein' => $workerDataFromEveree['taxpayerIdentifier'],
                    'entity_type' => 'business'
                ]);
            }


            $dataToUpdate = [
                'first_name' => isset($workerDataFromEveree['firstName'])?$workerDataFromEveree['firstName']:null,
                'middle_name' => isset($workerDataFromEveree['middleName'])?$workerDataFromEveree['middleName']:null,
                'last_name' => isset($workerDataFromEveree['lastName'])?$workerDataFromEveree['lastName']:null,
                'dob' => isset($workerDataFromEveree['dateOfBirth'])?$workerDataFromEveree['dateOfBirth']:null,
                'email' => isset($workerDataFromEveree['email'])?$workerDataFromEveree['email']:null,
                'mobile_no' => isset($workerDataFromEveree['phoneNumber'])?$workerDataFromEveree['phoneNumber']:null,
                // 'pay_rate' => $workerDataFromEveree['position']['current']['payRate']['amount'],
                // 'pay_type' => $workerDataFromEveree['position']['current']['payType'],
                //address
                'home_address_line_1' => isset($workerDataFromEveree['homeAddress']['current']['line1'])?$workerDataFromEveree['homeAddress']['current']['line1']:null,
                // 'home_address_line_2' => $workerDataFromEveree['homeAddress']['current']['asd'],
                'home_address_city' => isset($workerDataFromEveree['homeAddress']['current']['city'])?$workerDataFromEveree['homeAddress']['current']['city']:null,
                'home_address_state' => $home_address_state,
                'home_address_zip' => isset($workerDataFromEveree['homeAddress']['current']['postalCode'])?$workerDataFromEveree['homeAddress']['current']['postalCode']:null,
                //banking info
                'type_of_account' => isset($workerDataFromEveree['bankAccounts'][0])?$workerDataFromEveree['bankAccounts'][0]['accountType']:null,
                'name_of_bank' => isset($workerDataFromEveree['bankAccounts'][0])?$workerDataFromEveree['bankAccounts'][0]['bankName']:null,
                'account_name' => isset($workerDataFromEveree['bankAccounts'][0])?$workerDataFromEveree['bankAccounts'][0]['accountName']:null,
                'routing_no' => isset($workerDataFromEveree['bankAccounts'][0])?$workerDataFromEveree['bankAccounts'][0]['routingNumber']:null,
                'account_no' => isset($workerDataFromEveree['bankAccounts'][0])?$workerDataFromEveree['bankAccounts'][0]['accountNumberLast4']:null,
                
            ];

            if(isset($workerDataFromEveree['homeAddress']['current']['latitude']) && isset($workerDataFromEveree['homeAddress']['current']['longitude'])){
                $dataToUpdate['home_address_lat'] = $workerDataFromEveree['homeAddress']['current']['latitude'];
                $dataToUpdate['home_address_long'] = $workerDataFromEveree['homeAddress']['current']['longitude'];
            }

            if(isset($workerDataFromEveree['homeAddress']['current']['timeZone'])){
                $dataToUpdate['home_address_timezone'] = $workerDataFromEveree['homeAddress']['current']['timeZone'];
            }
    
            User::where('everee_workerId',$workerId)->update($dataToUpdate);

        }


        

        if ($request->onboardProcess == 1) {
            $CrmData = Crms::where('id', 3)->where('status', 1)->first();
            if ($CrmData) {
                if($userdata && $worker_type == '1099'){
                    $this->update_emp_personal_info($userdata, $userdata->state);  //update emp in everee
                }
            }

            OnboardingEmployees::where('user_id', $uid)->update(['status_id' => 14]);
            $CrmData = Crms::where('id', 2)->where('status', 1)->first();
            $CrmSetting = CrmSetting::where('crm_id', 2)->first();
            if (!empty($CrmData) && !empty($CrmSetting) && !empty($aveyoid)) {

                $decreptedValue = openssl_decrypt($CrmSetting['value'], env('ENCRYPTION_CIPHER_ALGO'), env('ENCRYPTION_KEY'), 0, env('ENCRYPTION_IV'));
                $val = json_decode($decreptedValue);
                $token = $val->api_key;
                $Hubspotdata['properties'] = [
                    "address" => isset($empUpdate->home_address) ? $empUpdate->home_address : null,
                    "dob" => isset($empUpdate->dob) ? $empUpdate->dob : null,
                    "birthday" => isset($empUpdate->dob) ? $empUpdate->dob : null,
                    "sex" => isset($empUpdate->sex) ? $empUpdate->sex : null,
                    "mobile_no" => isset($empUpdate->mobile_no) ? $empUpdate->mobile_no : null,
                    "sequifi_id" => isset($empUpdate->employee_id) ? $empUpdate->employee_id : null,
                    "status" => 'Active'
                ];
                $this->update_employees($Hubspotdata, $token, $uid, $aveyoid);
            }
            
                
            $integration = Integration::where(['name' => 'Hubspot Current Energy', 'status' => 1])->first();
            $hubspotCurrentEnergyToken = env('HUBSPOT_CURRENT_ENERGY_API_KEY');
            if (!empty($integration) && !empty($hubspotCurrentEnergyToken)) {
                $hubspotCurrentEnergyData['properties'] = [
                    "address" => isset($empUpdate->home_address) ? $empUpdate->home_address : null,
                    "date_of_birth" => isset($empUpdate->dob) ? $empUpdate->dob : null,
                    "gender" => isset($empUpdate->sex) ? $empUpdate->sex : null,
                    "phone" => isset($empUpdate->mobile_no) ? $empUpdate->mobile_no : null,
                    "sales_rep_id" => isset($empUpdate->employee_id) ? $empUpdate->employee_id : null,
                    "contact_status" => 'Active',
                    "contact_type" => "Sales Rep"
                ];
                $this->updateContactForHubspotCurrentEnergy($hubspotCurrentEnergyData, $hubspotCurrentEnergyToken, $uid, $aveyoid);
            }
            
        }
        $onboardingUserData=OnboardingEmployees::where('user_id',$uid)->first();        
        if($onboardingUserData){
            $this->saveDataToLGCY($uid);
        }
        
        return response()->json([
            'ApiName' => 'Update Employee Detail',
            'status' => true,
            'message' => 'Update Successfully.' . $JobnimbusMessage,
            'createComponentSessionOfWorkerIdRespose' => $createComponentSessionOfWorkerIdRespose,
            'worker_type' => $worker_type,

        ]);
    }











/*
=============================================== update Offer Expiry Date employee offer_expired =============================================== 
*/













    public function updateOfferExpiryDate($date = '')
    {
        $date = $date ? $date : date('Y-m-d');
        try {
            $employees = OnboardingEmployees::where('offer_expiry_date', '<', $date)->whereNull('user_id')
                ->whereIn('status_id', [1, 4, 6, 12])->get(); 
                
            $CrmData = Crms::where(['id' => 2, 'status' => 1])->first();
            $CrmSetting = CrmSetting::where('crm_id', 2)->first();
            if (!empty($CrmData) && !empty($CrmSetting)) {
                foreach ($employees as $employee) {
                    $isSigned = false;
                    if ($employee->status_id == '1') {
                        $isSigned = $this->checkAllDocumentsSignedOrNot($employee);
                    } else {
                        $this->offerExpiredEventPush($employee->id);
                        $employee->update(['status_id' => 5]);
                    }

                    if (!$isSigned) {
                        $val = json_decode($CrmSetting['value']);
                        $token = $val->api_key;
                        if (!empty($employee['aveyo_hs_id'])) {
                            $Hubspotdata['properties'] = ['status' => 'Offer Expired'];
                            $this->update_hubspot_data($Hubspotdata, $token, $employee['aveyo_hs_id']);
                        }
                        $this->offerExpiredEventPush($employee->id);
                        $employee->update(['status_id' => 5]);
                    }
                }
            } else {
                foreach ($employees as $employee) {
                    if ($employee->status_id == '1') {
                        $isSigned = $this->checkAllDocumentsSignedOrNot($employee);
                        if (!$isSigned) {
                            $this->offerExpiredEventPush($employee->id);
                            $employee->update(['status_id' => 5]);
                        }
                    } else {
                        $this->offerExpiredEventPush($employee->id);
                        $employee->update(['status_id' => 5]);
                    }
                }
            }
            return ['success' => true, 'message' => 'Status Updated Successfully!'];
        } catch (Exception $e) {
            $errors[] = [
                'pid' => 'Offer Expired',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            $data = [
                'email' => 'jay.mavani@silvergrey.in',
                'subject' => 'Offer Expired Status Command Failed!!',
                'template' => view('mail.excel-import-failed', ['errors' => $errors])
            ];
            $this->sendEmailNotification($data, true);
            return ['success' => false, 'message' => $e->getMessage() . ' ' . $e->getLine()];
        }
    }