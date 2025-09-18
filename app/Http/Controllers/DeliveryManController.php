<?php

namespace App\Http\Controllers;

use App\Models\DeliveryMan;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\Admin;
use App\Models\BusinessSetting;
use Gregwar\Captcha\CaptchaBuilder;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class DeliveryManController extends Controller
{

    public function getAblyToken(Request $request)
{
    $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();
    
    if (!$dm) {
        return response()->json(['error' => 'No autorizado.'], 401);
    }

    try {
        // Aquí generas el token de Ably para este repartidor específico
        // Usando la configuración de Ably de tu proyecto
        
        $ablyKey = config('services.ably.key'); // Tu Ably API key
        $clientId = 'driver_' . $dm->id;
        
        // Generar token usando Ably SDK o API
        $ably = new \Ably\AblyRest($ablyKey);
        $tokenDetails = $ably->auth->requestToken([
            'clientId' => $clientId,
            'capability' => [
                "delivery-tracking-{$dm->id}" => ['publish', 'subscribe'],
                "public:ubicacion-repartidor-{$dm->id}" => ['publish', 'subscribe']
            ]
        ]);
        
        return response()->json([
            'token' => $tokenDetails->token,
            'expires' => $tokenDetails->expires,
            'clientId' => $clientId
        ], 200);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Error generando token Ably: ' . $e->getMessage()
        ], 500);
    }
}
    
    public function canReceiveCashOrders(Request $request)
{
    $user = $request->user(); // repartidor autenticado con Sanctum u otro método

    $completedOrders = Order::where('delivery_man_id', $user->id)
                            ->where('status', 'delivered')
                            ->where('payment_method', 'online')
                            ->count();

    $canReceiveCash = $completedOrders >= 10;

    return response()->json([
        'can_receive_cash' => $canReceiveCash,
        'completed_online_orders' => $completedOrders
    ]);
}

    
    public function create()
    {
        $status = BusinessSetting::where('key', 'toggle_dm_registration')->first();
        if(!isset($status) || $status->value == '0')
        {
            Toastr::error(translate('messages.not_found'));
            return back();
        }

        $custome_recaptcha = new CaptchaBuilder;
        $custome_recaptcha->build();
        Session::put('six_captcha', $custome_recaptcha->getPhrase());

        return view('dm-registration', compact('custome_recaptcha'));
    }

    public function store(Request $request)
    {
        $status = BusinessSetting::where('key', 'toggle_dm_registration')->first();
        if(!isset($status) || $status->value == '0')
        {
            Toastr::error(translate('messages.not_found'));
            return back();
        }

        $recaptcha = Helpers::get_business_settings('recaptcha');
        if (isset($recaptcha) && $recaptcha['status'] == 1) {
            $request->validate([
                'g-recaptcha-response' => [
                    function ($attribute, $value, $fail) {
                        $secret_key = Helpers::get_business_settings('recaptcha')['secret_key'];
                        $gResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                            'secret' => $secret_key,
                            'response' => $value,
                            'remoteip' => \request()->ip(),
                        ]);

                        if (!$gResponse->successful()) {
                            $fail(translate('ReCaptcha Failed'));
                        }
                    },
                ],
            ]);
        } else if(session('six_captcha') != $request->custome_recaptcha)
        {
            Toastr::error(trans('messages.ReCAPTCHA Failed'));
            return back();
        }
        
        $globalMax = config('tootli.max_cash_balance_global', 1000);

        $request->validate([
            'f_name' => 'required|max:100',
            'l_name' => 'nullable|max:100',
            'identity_number' => 'required|max:30',
            'email' => 'required|unique:delivery_men',
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:10|unique:delivery_men',
            'zone_id' => 'required',
            'vehicle_id' => 'required',
            'earning' => 'required',
            'max_cash_balance' => ['nullable', 'numeric', 'min:0', 'max:' . $globalMax],
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
        ], [
            'f_name.required' => translate('messages.first_name_is_required'),
            'zone_id.required' => translate('messages.select_a_zone'),
            'vehicle_id.required' => translate('messages.select_a_vehicle'),
            'earning.required' => translate('messages.select_dm_type')
        ]);

        if ($request->has('image')) {
            $image_name = Helpers::upload('delivery-man/', 'png', $request->file('image'));
        } else {
            $image_name = 'def.png';
        }

        $id_img_names = [];
        if (!empty($request->file('identity_image'))) {
            foreach ($request->identity_image as $img) {
                $identity_image = Helpers::upload('delivery-man/', 'png', $img);
                array_push($id_img_names, ['img'=>$identity_image, 'storage'=> Helpers::getDisk()]);
            }
            $identity_image = json_encode($id_img_names);
        } else {
            $identity_image = json_encode([]);
        }

        $dm = New DeliveryMan();
        $dm->f_name = $request->f_name;
        $dm->l_name = $request->l_name;
        $dm->email = $request->email;
        $dm->phone = $request->phone;
        $dm->identity_number = $request->identity_number;
        $dm->identity_type = $request->identity_type;
        $dm->vehicle_id = $request->vehicle_id;
        $dm->zone_id = $request->zone_id;
        $dm->identity_image = $identity_image;
        $dm->image = $image_name;
        $dm->active = 0;
        $dm->earning = $request->earning;
        $dm->password = bcrypt($request->password);
        $dm->application_status= 'pending';
        $dm->max_cash_balance = min($request->input('max_cash_balance',350), $globalMax);
        
        \Log::info('Creando DeliveryMan', [
            'max_cash_balance_solicitado' => $request->input('max_cash_balance'),
            'max_cash_balance_global' => $globalMax,
            'max_cash_balance_final' => $dm->max_cash_balance,
        ]);
        
        $dm->save();


        try{
            $admin= Admin::where('role_id', 1)->first();

            if(config('mail.status') &&  Helpers::get_mail_status('registration_mail_status_dm') == '1' && Helpers::getNotificationStatusData('deliveryman','deliveryman_registration','mail_status')  ){
                Mail::to($request->email)->send(new \App\Mail\DmSelfRegistration('pending', $dm->f_name.' '.$dm->l_name));
            }
            if(config('mail.status') && Helpers::get_mail_status('dm_registration_mail_status_admin') == '1' && Helpers::getNotificationStatusData('admin','deliveryman_self_registration','mail_status')) {
                Mail::to($admin['email'])->send(new \App\Mail\DmRegistration('pending', $dm->f_name.' '.$dm->l_name));
            }
        }catch(\Exception $ex){
            info($ex->getMessage());
        }
        Toastr::success(translate('messages.application_placed_successfully'));
        return back();
    }
}
