<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Nefu\Nefuer;
use App\Entity\Student;
use App\Entity\Permission;
use App\Entity\College;
use App\Entity\Major;
use App\Service\WechatService;

class UserController extends Controller
{

    /**
     * 2、判断是否微信打开
     */
    public function index(WechatService $wechatService): JsonResponse
    {
        if ($this->session->has('openid')) {
            return $this->signByOpenid($this->request, $this->session);
        } elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false) {
            return $this->wxIn($wechatService);
        } else {
            return $this->toSign();
        }
    }

    /**
     * 检查是否登录
     */
    public function checkSign(): JsonResponse
    {
        $nefuer = $this->getNefuer();
        if (false == $nefuer) {
            return $this->error();
        } else {
            return $this->success();
        }
    }

    /**
     * 3、微信登录
     */
    public function wxIn(WechatService $wechatService)
    {
        $appid = $this->request->server->get('WX_APPID');
        $secret = $this->request->server->get('WX_SECRET');
        $wechat = $wechatService->getWechat();
        if ( ! $this->request->request->has('code')) {
            //4、获取openid
            $redirectUri = $this->generateUrl(
                'wxIn',
                array(),
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $url = $wechat->code($redirectUri, false);
            return $this->toUrl($url, '自动登录中');
        } else {
            //5、保存openid至session
            $code = $this->request->request->has('code');
            $info = $wechat->base($code);
            $openid = $info['openid'];
            $this->session->set('nefuer_openid', $openid);
            if ($this->signByOpenid()) {
                echo '<script>
                window.location.assign(document.referrer);</script>';die;
            } else {
                return $this->route('sign');
            }
        }
    }

    /**
     * 通过openid登录
     */
    private function signByOpenid(WechatService $wechatService): JsonResponse
    {
        $openid = $this->session->get('openid');
        //6、根据openid获取用户信息

        //8、检查是否绑定
        if(true/* condition */) {
            //9、绑定：登录
            $rst = $this->sign($wechatService, $account, $password);
            if (false === $rst) {
                $this->session->set('nefuer_account', $account);
                $this->session->set('nefuer_password', $password);
            }
            return true;
        } else {
            //14、未绑定，返回登录
            return false;
        }
        
    }

    /**
     * 登录
     */
    public function sign(WechatService $wechatService, $account = null, $password = null)
    {
        $local = ( ! is_null($account));
        $account  = $this->request->request->get('account');
        $password = $this->request->request->get('password');
        if (empty($account)) {
            return $this->error(self::PARAM_MISS, '账号不能为空');
        }
        if (empty($password)) {
            return $this->error(self::PARAM_MISS, '密码不能为空');
        }

        $nefuer = new Nefuer();
        $login = $nefuer->login($account, $password);
        
        switch($login['code']) {
            case 100:
                if ($local) {
                    return false;
                } else {
                    return $this->success();
                }
            case 201:
                return $this->error(null, '账号或密码错误');
        }
        
        $studentDb = $this->getDoctrine()->getRepository(Student::class);
        $manager = $this->getDoctrine()->getEntityManager();
        $openid = $this->session->get('nefuer_openid', '');
        if ($openid != '') {
            $student = $studentDb->createQueryBuilder('s')
                ->andWhere('s.openid = :openid')
                ->setParameter('openid', $openid)
                ->getQuery()
                ->getOneOrNullResult()
            ;
            if ( ! is_null($student) && $student->getAccount() != $account) {
                $wechatService->sendUnbind($openid, $student->getAccount());
                $student->setOpenid('');
                $manager->persist($student);
                $manager->flush();
            }
        }
        $student = $studentDb->createQueryBuilder('s')
            ->andWhere('s.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        if (is_null($student)) {
            $student = $nefuer->info();
            if ( ! isset($student['code'])) {
                $collegeDb = $this->getDoctrine()->getRepository(College::class);
                $majorDb = $this->getDoctrine()->getRepository(Major::class);
                $collegeId = $collegeDb->getId($student['college']);
                $majorId = $majorDb->getId($student['major'], $collegeId);
                switch ($student['sex']) {
                    case '男':
                        $student['sex'] = 1;
                        break;
                    case '女':
                        $student['sex'] = 2;
                        break;
                    default:
                        $student['sex'] = 0;
                        break;
                }
                $student = array(
                    'account' => $account,
                    'password' => $password,
                    'name' => $student['name'],
                    'openid' => $openid,
                    'majorId' => $majorId,
                    'sex' => $student['sex'],
                    'grade' => (int)substr($account, 0, 4),
                );
                $studentDb->insert(array($student));
                $permissionDb = $this->getDoctrine()->getRepository(Permission::class);
                $permissionDb->insert(array(
                    array(
                        'name' => '成绩',
                        'account' => $account,
                        'permit' => true,
                    ),
                    array(
                        'name' => '阶段成绩',
                        'account' => $account,
                        'permit' => true,
                    ),
                    array(
                        'name' => '考试',
                        'account' => $account,
                        'permit' => true,
                    ),
                ));
            }
        } elseif ($openid != '' && $student->getOpenid() != $openid) {
            $wechatService->sendUnbind($student->getOpenid(), $account);
            $wechatService->sendbind($openid, $account);
            $student->setOpenid($openid);
            $student->setPassword($password);
            $manager->persist($student);
            $manager->flush();
        } elseif ($password != $student->getPassword()) {
            $student->setPassword($password);
            $manager->persist($student);
            $manager->flush();
        }

        $this->session->set('nefuer_account', $account);
        $this->session->set('nefuer_password', $password);
        $this->session->set('nefuer_cookie', $nefuer->getCookie());
        return $this->success();
    }
}
