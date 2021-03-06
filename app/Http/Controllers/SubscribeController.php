<?php

namespace App\Http\Controllers;

use App\Components\Helpers;
use App\Http\Models\Device;
use App\Http\Models\SsGroup;
use App\Http\Models\SsNode;
use App\Http\Models\User;
use App\Http\Models\UserLabel;
use App\Http\Models\UserSubscribe;
use App\Http\Models\UserSubscribeLog;
use Illuminate\Http\Request;

// cncdn
use App\Http\Models\Cncdn;

use Redirect;
use Response;

/**
 * 订阅控制器
 *
 * Class SubscribeController
 *
 * @package App\Http\Controllers
 */
class SubscribeController extends Controller
{
    protected static $systemConfig;

    function __construct()
    {
        self::$systemConfig = Helpers::systemConfig();
    }

    // 订阅码列表
    public function subscribeList(Request $request)
    {
        $user_id = $request->get('user_id');
        $username = $request->get('username');
        $status = $request->get('status');

        $query = UserSubscribe::with(['User']);

        if (!empty($user_id)) {
            $query->where('user_id', $user_id);
        }

        if (!empty($username)) {
            $query->whereHas('user', function ($q) use ($username) {
                $q->where('username', 'like', '%' . $username . '%');
            });
        }

        if ($status != '') {
            $query->where('status', intval($status));
        }

        $view['subscribeList'] = $query->orderBy('id', 'desc')->paginate(20)->appends($request->except('page'));

        return Response::view('subscribe.subscribeList', $view);
    }

    // 订阅设备列表
    public function deviceList(Request $request)
    {
        $type = intval($request->get('type'));
        $platform = intval($request->get('platform'));
        $name = trim($request->get('name'));
        $status = intval($request->get('status'));

        $query = Device::query();

        if (!empty($type)) {
            $query->where('type', $type);
        }

        if ($platform != '') {
            $query->where('platform', $platform);
        }

        if (!empty($name)) {
            $query->where('name', 'like', '%' . $name . '%');
        }

        if ($status != '') {
            $query->where('status', $status);
        }

        $view['deviceList'] = $query->paginate(20)->appends($request->except('page'));

        return Response::view('subscribe.deviceList', $view);
    }

    // 设置用户的订阅的状态
    public function setSubscribeStatus(Request $request)
    {
        $id = $request->get('id');
        $status = $request->get('status', 0);

        if (empty($id)) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '操作异常']);
        }

        if ($status) {
            UserSubscribe::query()->where('id', $id)->update(['status' => 1, 'ban_time' => 0, 'ban_desc' => '']);
        } else {
            UserSubscribe::query()->where('id', $id)->update(['status' => 0, 'ban_time' => time(), 'ban_desc' => '后台手动封禁']);
        }

        return Response::json(['status' => 'success', 'data' => '', 'message' => '操作成功']);
    }

    // 设置设备是否允许订阅的状态
    public function setDeviceStatus(Request $request)
    {
        $id = intval($request->get('id'));
        $status = intval($request->get('status', 0));

        if (empty($id)) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '操作异常']);
        }

        Device::query()->where('id', $id)->update(['status' => $status]);

        return Response::json(['status' => 'success', 'data' => '', 'message' => '操作成功']);
    }

    // 通过订阅码获取订阅信息
    public function getSubscribeByCode(Request $request, $code)
    {
        if (empty($code)) {
            return Redirect::to('login');
        }

#Song 获取查询字符串
        $ver = $request->get('ver');
        $ss_sub = $request->get('ss');
        $sr_sub = $request->get('ssr');
        $v2ray_sub = $request->get('v2ray');
        $rocket_sub = $request->get('rocket');
        $cn_sub = $request->get('cn');
        $cf_sub = $request->get('cf');
#end
        // 校验合法性
        $subscribe = UserSubscribe::query()->with('user')->where('status', 1)->where('code', $code)->first();
        if (!$subscribe) {
            exit($this->noneNode());
        }

        $user = User::query()->where('status', 1)->where('enable', 1)->where('id', $subscribe->user_id)->first();
        if (!$user) {
            exit($this->noneNode());
        }

        // 更新访问次数
        $subscribe->increment('times', 1);
        //今日访问也+1
        $subscribe->increment('times_today', 1);
        //记录用户订阅IP
        $user->rss_ip = getClientIp();
        //记录如果用户设置 cfcdn 计入统计次数
        if ($user->cfcdn) {
          $user->cfcdn_count += 1;
        }
        $user->save();

        // 记录每次请求
        $this->log($subscribe->id, getClientIp(), $request->headers);

        // 这里已被 level 等级代替，无需获取标签了
        /*
        // 获取这个账号可用节点
        $userLabelIds = UserLabel::query()->where('user_id', $user->id)->pluck('label_id');
        if (empty($userLabelIds)) {
            exit($this->noneNode());
        }
        */

        $query = SsNode::query()->selectRaw('ss_node.*')->leftjoin("ss_node_label", "ss_node.id", "=", "ss_node_label.node_id");
/* song
        // 启用混合订阅时，加入V2Ray节点，未启用时仅下发SSR节点信息
        if (!self::$systemConfig['mix_subscribe']) {
            $query->where('ss_node.type', 1);
        }

*/     // 这里 等级 level 和 分组 group 获取节点
        $nodeList = $query->where('ss_node.status', 1)->where('level', '<=' ,$user->level)->where('node_group',$user->node_group)->where('ss_node.is_subscribe', 1)->groupBy('ss_node.id')->orderBy('ss_node.level', 'desc')->orderBy('ss_node.node_onload', 'desc')->take(self::$systemConfig['subscribe_max'])->get()->toArray();
        if (empty($nodeList)) {
            exit($this->noneNode());
        }

        // 打乱数组
        if (self::$systemConfig['rand_subscribe']) {
            shuffle($nodeList);
        }

        // 控制客户端最多获取节点数
        $scheme = '';
/**song
        // 展示到期时间和剩余流量
        if (self::$systemConfig['is_custom_subscribe']) {
            $scheme .= $this->expireDate($user);
            $scheme .= $this->lastTraffic($user);
        }
**/

//song add ver
        if ($ver == "1" || !empty($sr_sub)) {
            # code...
            foreach ($nodeList as $key => $node) {
                $node_warm = '';
                $node['traffic_rate'] < 0.3 && $node_warm = '' ;
                // 控制显示的节点数
                if (!empty($sr_sub) && $key >= $sr_sub) {
                    break;
                }
                //
                if ($node['type'] == 1) {
                    if ( empty($node['monitor_url']) ) {
                        # code...
                        //$group = SsGroup::query()->where('id', $node['group_id'])->first();
                        $group = self::$systemConfig['website_name'];
                        $obfs_param = $user->obfs_param ? $user->obfs_param : $node['obfs_param'];
                        $protocol_param = $node['single'] ? $user->port . ':' . $user->passwd : $user->protocol_param;
                        // 生成ssr scheme
                        $ssr_str = ($node['server'] ? $node['server'] : $node['ip']) . ':' . ($node['single'] ? $node['single_port'] : $user->port);
                        $ssr_str .= ':' . ($node['single'] ? $node['single_protocol'] : $user->protocol) . ':' . ($node['single'] ? $node['single_method'] : $user->method);
                        $ssr_str .= ':' . ($node['single'] ? $node['single_obfs'] : $user->obfs) . ':' . ($node['single'] ? base64url_encode($node['single_passwd']) : base64url_encode($user->passwd));
                        $ssr_str .= '/?obfsparam=' . base64url_encode($obfs_param);
                        $ssr_str .= '&protoparam=' . ($node['single'] ? base64url_encode($user->port . ':' . $user->passwd) : base64url_encode($protocol_param));
                        $ssr_str .= '&remarks=' . base64url_encode($node['name'].' ·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm);
                        $ssr_str .= '&group=' . base64url_encode($group);
                        $ssr_str .= '&udpport=0';
                        $ssr_str .= '&uot=0';
                        $ssr_str = base64url_encode($ssr_str);
                        $scheme .= 'ssr://' . $ssr_str . "\n";
                    }elseif ( $node['compatible'] ) {
                        # code...
                        //$group = SsGroup::query()->where('id', $node['group_id'])->first();
                        $group = self::$systemConfig['website_name'];
                        // 生成ssr scheme
                        $ssr_str = ($node['server'] ? $node['server'] : $node['ip']) . ':' . $node['ssh_port'];
                        $ssr_str .= ':origin' . ':' . $node['method'];
                        $ssr_str .= ':plain' . ':' . base64url_encode($node['monitor_url']);
                        $ssr_str .= '/?obfsparam=';
                        $ssr_str .= '&protoparam=';
                        $ssr_str .= '&remarks=' . base64url_encode($node['name'].' ·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm);
                        $ssr_str .= '&group=' . base64url_encode($group);
                        $ssr_str .= '&udpport=0';
                        $ssr_str .= '&uot=0';
                        $ssr_str = base64url_encode($ssr_str);
                        $scheme .= 'ssr://' . $ssr_str . "\n";
                    }
                }
            }
            //add time 和流量
            $scheme .= $this->expireDate($user);
            $scheme .= $this->lastTraffic($user);
        }

        if ($ver == "2" || !empty($v2ray_sub)) {
            foreach ($nodeList as $key => $node) {
                // 控制显示的节点数
                if(!empty($v2ray_sub) && $key > $v2ray_sub){
                    break;
                }

                //控制显示 cncdn 自定义
                $node_server = $node['server'];
                $cdn_area = '';
                // if ($node['is_transit'] ) {   //如果用户设置了 cncdn 而且设置了 中转
                //     if ($user->cncdn || $cn_sub) {
                //       $cdn_server = strstr($node_server, '.');
                //       $cdn_server = substr($cdn_server, 1);
                //       empty($cn_sub) && $cn_sub = $user->cncdn;
                //       $cncdn = Cncdn::where('status','=','1')->where('server','=',$cdn_server)->where('area','=',$cn_sub)->orderBy('id','desc')->first();
                //       if (!empty($cncdn->ipmd5)) {
                //           $node_server = $cncdn->ipmd5.'.'.$cncdn->host;
                //           $cdn_area = $cncdn->area;
                //       }
                //     }else{
                //       $cdn_server = strstr($node_server, '.');
                //       $cdn_server = substr($cdn_server, 1);
                //       $cdn_area_array = array("武汉联通","郑州联通","天津联通","重庆联通","济南联通","广州联通","石家庄联通","天津移动","广州移动","无锡移动");
                //       $cdn_area_choice = $cdn_area_array[array_rand($cdn_area_array,1)];
                //       $cncdn = Cncdn::where('status','=','1')->where('server','=',$cdn_server)->where('area','=',$cdn_area_choice)->orderBy('id','desc')->first();
                //       if (!empty($cncdn->ipmd5)) {
                //           $node_server = $cncdn->ipmd5.'.'.$cncdn->host;
                //           $cdn_area = $cncdn->area;
                //       }
                //     }
                //
                // }elseif ($user->cfcdn || $cf_sub) {
                //     $node_server = $user->cfcdn;
                //     !empty($cf_sub) && $node_server = $cf_sub;
                // }
                // 设置 cfcdn 这个配置，只有在 istransit 的节点才能用
                if ($user->cfcdn || $cf_sub ) {
                  if ($node['is_transit']) {
                    $node_server = $user->cfcdn;
                    !empty($cf_sub) && $node_server = $cf_sub;
                    $cdn_area = $node_server;
                  }
                }

                //
                $node_warm = '';
                // 获取分组名称
                if ($node['type'] == 2) {
                    // 生成v2ray scheme
                    $v2_json = [
                        "v"    => "2",
                        "ps"   => $node['name'].$cdn_area.'·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm,
                        "add"  => $node['server'] ? $node_server : $node['ip'],
                        "port" => $node['v2_port'],
                        "id"   => $node['monitor_url'] ? $node['monitor_url'] : $user['vmess_id'],
                        "aid"  => $node['v2_alter_id'],
                        "net"  => $node['v2_net'],
                        "type" => $node['v2_type'],
                        "host" => $node['v2_host'],
                        "path" => $node['v2_path'],
                        "tls"  => $node['v2_tls'] == 1 ? "tls" : ""
                    ];
                    $scheme .= 'vmess://' . base64_encode(json_encode($v2_json)) . "\n";

                    // 增加端口2号节点
                    if (!empty($node['v2_insider_port'])) {
                        $v2_port2_json = [
                            "v"    => "2",
                            "ps"   => $node['name'].$cdn_area.'·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm,
                            "add"  => $node['server'] ? $node_server : $node['ip'],
                            "port" => $node['v2_insider_port'],
                            "id"   => $node['monitor_url'] ? $node['monitor_url'] : $user['vmess_id'],
                            "aid"  => $node['v2_alter_id'],
                            "net"  => $node['v2_net'],
                            "type" => $node['v2_type'],
                            "host" => $node['v2_host'],
                            "path" => $node['v2_path'],
                            "tls"  => $node['v2_tls'] == 1 ? "tls" : ""
                        ];
                        $scheme .= 'vmess://' . base64_encode(json_encode($v2_port2_json)) . "\n";
                    }

                    // 增加端口3号节点
                    if (!empty($node['v2_outsider_port'])) {
                        $v2_port3_json = [
                            "v"    => "2",
                            "ps"   => $node['name'].$cdn_area.'·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm,
                            "add"  => $node['server'] ? $node_server : $node['ip'],
                            "port" => $node['v2_outsider_port'],
                            "id"   => $node['monitor_url'] ? $node['monitor_url'] : $user['vmess_id'],
                            "aid"  => $node['v2_alter_id'],
                            "net"  => $node['v2_net'],
                            "type" => $node['v2_type'],
                            "host" => $node['v2_host'],
                            "path" => $node['v2_path'],
                            "tls"  => $node['v2_tls'] == 1 ? "tls" : ""
                        ];
                        $scheme .= 'vmess://' . base64_encode(json_encode($v2_port3_json)) . "\n";
                    }

                }
            }
        }

        if ( !empty($ss_sub) ) {
            foreach ($nodeList as $key => $node) {
                // 控制显示的节点数
                if ($key >= $ss_sub) {
                    break;
                }

                $node_warm = '';
                $node['traffic_rate'] < 0.3 && $node_warm = '' ;
                // 获取分组名称
                if ( empty($node['monitor_url']) ) {
                    # code...
                    if ( $node['compatible'] ) {
                    $ss_str = $user['method'] . ':' . $user['passwd'] . '@';
                    $ss_str .= ($node['server'] ? $node['server'] : $node['ip']) . ':' . $user['port'];
                    $ss_str = base64_encode($ss_str) . '#' . $node['name'].' ·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm;
                    $scheme .= 'ss://' . $ss_str . "\n";
                    }
                }else{
                    $ss_str = $node['method'] . ':' . $node['monitor_url'] . '@';
                    $ss_str .= ($node['server'] ? $node['server'] : $node['ip']) . ':' . $node['ssh_port'];
                    $ss_str = base64_encode($ss_str) . '#' . $node['name'].' ·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm;
                    $scheme .= 'ss://' . $ss_str . "\n";
                }
            }
            //增加  剩余时间和流量
        }

        if ($ver == "3" || !empty($rocket_sub) ) { //
            # 这个是小火箭的订阅规则 嘎嘎
            foreach ($nodeList as $key => $node) {
                $node_warm = '';
                $node['traffic_rate'] < 0.3 && $node_warm = '' ;
                // 控制显示的节点数
                if (self::$systemConfig['subscribe_max'] && $key >= self::$systemConfig['subscribe_max']) {
                    break;
                }

                //控制显示 cncdn 自定义
                $node_server = $node['server'];
                $cdn_area = '';
                // if ($node['is_transit'] ) {   //如果用户设置了 cncdn 而且设置了 中转
                //     if ($user->cncdn || $cn_sub) {
                //       $cdn_server = strstr($node_server, '.');
                //       $cdn_server = substr($cdn_server, 1);
                //       empty($cn_sub) && $cn_sub = $user->cncdn;
                //       $cncdn = Cncdn::where('status','=','1')->where('server','=',$cdn_server)->where('area','=',$cn_sub)->orderBy('id','desc')->first();
                //       if (!empty($cncdn->ipmd5)) {
                //           $node_server = $cncdn->ipmd5.'.'.$cncdn->host;
                //           $cdn_area = $cncdn->area;
                //       }
                //     }else{
                //       $cdn_server = strstr($node_server, '.');
                //       $cdn_server = substr($cdn_server, 1);
                //       $cdn_area_array = array("武汉联通","郑州联通","天津联通","重庆联通","济南联通","广州联通","石家庄联通","天津移动","广州移动","无锡移动");
                //       $cdn_area_choice = $cdn_area_array[array_rand($cdn_area_array,1)];
                //       $cncdn = Cncdn::where('status','=','1')->where('server','=',$cdn_server)->where('area','=',$cdn_area_choice)->orderBy('id','desc')->first();
                //       if (!empty($cncdn->ipmd5)) {
                //           $node_server = $cncdn->ipmd5.'.'.$cncdn->host;
                //           $cdn_area = $cncdn->area;
                //       }
                //     }
                // }elseif ($user->cfcdn || $cf_sub) {
                //     $node_server = $user->cfcdn;
                //     !empty($cf_sub) && $node_server = $cf_sub;
                // }
                if ($user->cfcdn || $cf_sub ) {
                  if ($node['is_transit']) {
                    $node_server = $user->cfcdn;
                    !empty($cf_sub) && $node_server = $cf_sub;
                    $cdn_area = $node_server;
                  }
                }

                //
                if ($node['type'] == 2 && $node['v2_net'] != 'kcp') {
                    $v2_str = $node['v2_method'] . ':' . ($node['monitor_url'] ? $node['monitor_url'] : $user['vmess_id']) . '@';
                    $v2_str .= ($node['server'] ? $node_server : $node['ip']) . ':' . $node['v2_port'];
                    $v2_str = base64url_encode($v2_str) . '?remarks=' . urlencode($node['name'].$cdn_area.'·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm) ;
                    $v2_str .= '&obfsParam=' . $node['v2_host'] . '&path=' . $node['v2_path'] . '&obfs=' . ($node['v2_net'] == 'ws' ? 'websocket' : $node['v2_net']) . '&tls=' . ($node['v2_tls'] == 1 ? "1" : "").'&peer='.$node['v2_host'].'&allowInsecure=1';
                    $scheme .= 'vmess://' . $v2_str . "\n";
                }else{
                    if ( empty($node['monitor_url']) ) {
                        # code...
                        if ( $node['compatible'] ) {
                        $ss_str = $user['method'] . ':' . $user['passwd'] . '@';
                        $ss_str .= ($node['server'] ? $node['server'] : $node['ip']) . ':' . $user['port'];
                        $ss_str = base64_encode($ss_str) . '#' . urlencode($node['name'].'·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm);
                        $scheme .= 'ss://' . $ss_str . "\n";
                        }
                    }else{
                        $ss_str = $node['method'] . ':' . $node['monitor_url'] . '@';
                        $ss_str .= ($node['server'] ? $node['server'] : $node['ip']) . ':' . $node['ssh_port'];
                        $ss_str = base64_encode($ss_str) . '#' . urlencode($node['name'].'·'.$node['level'].'#'.$node['id'].'|'.$node['traffic_rate'].':'.($node['node_cost']/5).'|'.$node['node_online'].$node_warm);
                        $scheme .= 'ss://' . $ss_str . "\n";
                    }
                }
            }
        }

        exit(base64_encode($scheme));

    }

    // 写入订阅访问日志
    private function log($subscribeId, $ip, $headers)
    {
        $log = new UserSubscribeLog();
        $log->sid = $subscribeId;
        $log->request_ip = $ip;
        $log->request_time = date('Y-m-d H:i:s');
        $log->request_header = $headers;
        $log->save();
    }

    // 抛出无可用的节点信息，用于兼容防止客户端订阅失败
    private function noneNode()
    {
        return base64url_encode('ssr://' . base64url_encode('0.0.0.0:1:origin:none:plain:' . base64url_encode('0000') . '/?obfsparam=&protoparam=&remarks=' . base64url_encode('检查账号！网站' . Helpers::systemConfig()['website_name'] .'欢迎您') . '&group=' . base64url_encode('错误') . '&udpport=0&uot=0') . "\n");
    }

    /**
     * 过期时间
     *
     * @param object $user
     *
     * @return string
     */
    private function expireDate($user)
    {
        $text = '到期时间：' . $user->expire_time;

        return 'ssr://' . base64url_encode('0.0.0.1:1:origin:none:plain:' . base64url_encode('0000') . '/?obfsparam=&protoparam=&remarks=' . base64url_encode($text) . '&group=' . base64url_encode(Helpers::systemConfig()['website_name']) . '&udpport=0&uot=0') . "\n";
    }

    /**
     * 剩余流量
     *
     * @param object $user
     *
     * @return string
     */
    private function lastTraffic($user)
    {
        $text = '剩余流量：' . flowAutoShow($user->transfer_enable - $user->u - $user->d);

        return 'ssr://' . base64url_encode('0.0.0.2:1:origin:none:plain:' . base64url_encode('0000') . '/?obfsparam=&protoparam=&remarks=' . base64url_encode($text) . '&group=' . base64url_encode(Helpers::systemConfig()['website_name']) . '&udpport=0&uot=0') . "\n";
    }

    /**
     * 用户信息 v2ray
     *
     * @param object $user
     *
     * @return string
     */
    private function userInfoV2ray($user)
    {
        $text = '到期时间：' . $user->expire_time;

        return 'ssr://' . base64url_encode('0.0.0.1:1:origin:none:plain:' . base64url_encode('0000') . '/?obfsparam=&protoparam=&remarks=' . base64url_encode($text) . '&group=' . base64url_encode(Helpers::systemConfig()['website_name']) . '&udpport=0&uot=0') . "\n";
    }
}
