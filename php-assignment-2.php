<?php 
public function create_payment(Request $request)
    {
        $req_user_id = '';
        $type_id = '';
        $req_id = '';
        $req_amount = '';
        $req_no = '';
        $req_des = ''; 
    
        try {
            $Validator = Validator::make($request->all(), [
                'user_id' => 'required',
                'amount' => ['required', 'numeric', function ($attribute, $value, $fail) {
                    if ($value <= 0) {
                        $fail('The :attribute must be a positive number.');
                    }
                }],
                'adjustment_type_id' => 'required',
                // 'req_id' => 'required',
            ]);
    
            if ($Validator->fails()) {
                return response()->json([
                    'error' => $Validator->errors()
                ], 400);
            }
    
            if ($request->adjustment_type_id == 5) {
                return response()->json([
                    'error' => ['adjustment_type_id' => ['Invalid adjustment type ' . $request->adjustment_type_id]]
                ], 400);
            }
    
            $unclearPayroll = Payroll::where('user_id', $request->user_id)->whereIn('finalize_status', [1, 2])->first();
    
            if ($unclearPayroll) {
                return response()->json([
                    'error' => ['mmessege' => ['At this time, we are unable to process your request. Our system is currently finalizing and executing the payroll. Please try again later. Thank you for your patience.']]
                ], 400);
            }
    
            $CrmData = Crms::where('id', 3)->where('status', 1)->first();
            $CrmSetting = CrmSetting::where('crm_id', 3)->first();
    
            if (empty($CrmData) || empty($CrmSetting)) {
                return response()->json([
                    'error' => ['messege' => ['You are presently not set up to utilize Sequifi\'s payment services. Therefore, this payment cannot be processed. Please reach out to your system administrator.']]
                ], 400);
            }
            else{
                $req_data = ApprovalsAndRequest::where('id', $request->req_id)->where('status', 'Approved')->first();
                if ($req_data || $req_id == "") {
                    $req_user_id = isset($req_data->user_id) ? $req_data->user_id : $request->user_id;
                    $type_id = isset($req_data->adjustment_type_id) ? $req_data->adjustment_type_id : $request->adjustment_type_id;
                    $req_id = isset($req_data->id) ? $req_data->id : null;
                    $req_amount = isset($req_data->amount) ? $req_data->amount : $request->amount;
                    $req_no = isset($req_data->req_no) ? $req_data->req_no : null;
                    $req_des = isset($req_data->description) ? $req_data->description : null;
        
                    $uid = isset($request->user_id) ? $request->user_id : $req_user_id;
                    $user = User::where('id', $uid)->first();
                    $positionPayFrequency = PositionPayFrequency::query()->where(['position_id' => $user->sub_position_id])->first();
                    if(!$positionPayFrequency){
                        return response()->json([
                            'error' => ['messege' => ['sorry user doesn\'t have any position pay frequency that\'s why we are unable to process right now.']]
                        ], 400);
                    }

                    $check = OneTimePayments::where('adjustment_type_id', $type_id)->count();
                    $CrmData = Crms::where('id', 3)->where('status', 1)->first();
                    $CrmSetting = CrmSetting::where('crm_id', 3)->first();
        
                    if ($user && ( $user->employee_id == null || $user->employee_id == "" || $user->dataeve_workerId == null || $user->dataeve_workerId == "")) {
                        return response()->json([
                            'ApiName' => 'one_time_payment',
                            'status' => false,
                            'message' => "Since the user has not completed their self-onboarding process and their information is incomplete, we are unable to process the payment. Please ensure their details are fully updated to proceed with the payment.",
                        ], 400);
                    }
        
                    if (!empty($CrmData) && !empty($CrmSetting)) {
                        if ($type_id == 1) {
                            if (!empty($check)) {
                                $req_no = 'OTPD' . str_pad($check + 1, 6, '0', STR_PAD_LEFT);
                            } else {
                                $req_no = 'OTPD' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        } elseif ($type_id == 2) {
                            if (!empty($check)) {
                                $req_no = 'OTR' . str_pad($check + 1, 6, '0', STR_PAD_LEFT);
                            } else {
                                $req_no = 'OTR' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        } elseif ($type_id == 3) {
                            if (!empty($check)) {
                                $req_no = 'OTB' . str_pad($check + 1, 6, '0', STR_PAD_LEFT);
                            } else {
                                $req_no = 'OTB' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        } elseif ($type_id == 4) {
                            if (!empty($check)) {
                                $req_no = 'OTA' . str_pad($check + 1, 6, '0', STR_PAD_LEFT);
                            } else {
                                $req_no = 'OTA' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        } elseif ($type_id == 6) {
                            if (!empty($check)) {
                                $req_no = 'OTI' . str_pad($check + 1, 6, '0', STR_PAD_LEFT);
                            } else {
                                $req_no = 'OTI' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        } elseif ($type_id == 10) {
                            if (!empty($request->customer_pid)) {
                                $req_no = 'OTC' . $request->customer_pid;
                            } else {
                                $req_no = 'OTC' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        } elseif ($type_id == 11) {
                            if (!empty($check)) {
                                $req_no = 'OTOV' . str_pad($check + 1, 6, '0', STR_PAD_LEFT);
                            } else {
                                $req_no = 'OTOV' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        } else {
                            if (!empty($check)) {
                                $req_no = 'OTO' . str_pad($check + 1, 6, '0', STR_PAD_LEFT);
                            } else {
                                $req_no = 'OTO' . str_pad('000000' + 1, 6, '0', STR_PAD_LEFT);
                            }
                        }
        
                        $external_id = $user->employee_id . "-" . strtotime('now');
                        $amount = isset($request->amount) ? $request->amount : $req_amount;
                        $Fields = [
                            'usersdata' => [
                                'employee_id' => $user->employee_id,
                                'dataeve_workerId' => $user->dataeve_workerId,
                                'id' => $user->id,
                            ],
                            'net_pay' => $amount,
                            'payable_type' => 'one time payment',
                            'payable_label' => 'one time payment'
                        ];
        
                        if ($type_id == 2) {
                            $payable = $this->add_payable($dataeveFields, $external_id, 'REIMBURSEMENT');  
                        } else {
                            $payable = $this->add_payable($dataeveFields, $external_id, 'COMMISSION');  
                        }
        
                        if ((isset($payable['success']['status']) && $payable['success']['status'] == true)) {
                            $onetimePayment = 1;
                            $payable_request = $this->payable_request($dataeveFields, $onetimePayment);
                            $date = date('Y-m-d');
                            if ($req_id != "") {
                                $req_data->status = 'Accept';
                                $req_data->payroll_id = 0;
                                $req_data->pay_period_from = $date;
                                $req_data->pay_period_to = $date;
                                $req_data->save();
                            }

                            create_paystub_employee([
                                'user_id' => $uid,
                                'pay_period_from' =>date('Y-m-d') , 
                                'pay_period_to' =>date('Y-m-d')
                            ]);
        
                            $response = OneTimePayments::create([
                                'user_id' => $uid,
                                'req_id' => $req_id ? $req_id : null,
                                'pay_by' => Auth::user()->id,
                                'req_no' => $req_no ? $req_no : null,
                                'dataeve_external_id' => $external_id,
                                'dataeve_payment_req_id' => isset($payable_request['success']['paymentId']) ? $payable_request['success']['paymentId'] : null,
                                'dataeve_paymentId' => isset($payable_request['success']['dataeve_payment_id']) ? $payable_request['success']['dataeve_payment_id'] : null,
                                'adjustment_type_id' => $type_id,
                                'amount' => $amount,
                                'description' => $request->description ? $request->description : $req_des,
                                'pay_date' => date('Y-m-d'),
                                'payment_status' => 3,
                                'dataeve_status' => 1,
                                'dataeve_json_response' => isset($payable_request) ? json_encode($payable_request) : null,
                                'dataeve_webhook_response' => null,
                                'dataeve_payment_status' => 0,
                            ]);
                            $attributes = $request->all();
        
                            // Merge additional keys into the attributes array
                            $additionalProperties = [
                                'req_no' => $response->req_no, // Include request number
                                'dataeve_paymentId' => $response->dataeve_paymentId,
                                'payment_status' => $response->payment_status,
                                'dataeve_payment_status' => $response->dataeve_payment_status
                            ];
                        
                            $mergedProperties = array_merge($attributes, $additionalProperties);
        
                            // Log activity
                            activity()
                                ->causedBy(Auth::user()) // The user who triggered the action
                                ->performedOn($response) // The OneTimePayments record
                                ->withProperties(['attributes' => $mergedProperties]) 
                                ->event('created')
                                ->log('One-time payment created'); // Log description
        
                            return response()->json([
                                'ApiName' => 'one_time_payment',
                                'status' => true,
                                'message' => 'success!',
                                'dataeve_response' => $payable['success']['dataeve_response'],
                                'data' => $response
                            ], 200);
                        } else {
                            $payable['fail']['dataeve_response']['errorMessage'] =  isset($payable['fail']['dataeve_response']['errorMessage']) ? $payable['fail']['dataeve_response']['errorMessage'] : (isset($payable['fail']['dataeve_response']['error']) ? $payable['fail']['dataeve_response']['error'] : "An error occurred during the dataeve payment process.");
                            return response()->json([
                                "status" => false,
                                "message" => $payable['fail']['dataeve_response']['errorMessage'],
                                "ApiName" => "one_time_payment",
                                'response'=> $payable['fail']['dataeve_response']
                            ], 400);
                        }
                    }
                } else {
                    return response()->json([
                        "status" => false,
                        "message" => "Sorry the request you are looking for is not found.",
                    ], 400);
                }
            }
    
        } catch (\Exception $e) {
                 // Log activity for failed payment creation
                 activity()
                 ->causedBy(Auth::user())
                 ->withProperties(['error' => $e->getMessage()])
                 ->log('Failed to create one-time payment');
            return response()->json([
                "status" => false,
                "message" => $e->getMessage(),
                "Line" => $e->getLine(),
                "File" => $e->getFile(),
            ], 400); 
        }
    }