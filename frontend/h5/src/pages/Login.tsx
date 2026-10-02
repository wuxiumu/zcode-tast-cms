import { useState } from 'react'
import { Button, Input, Toast } from 'antd-mobile'
import { useLocation, useNavigate } from 'react-router-dom'
import { api, setToken } from '../api'

export default function Login() {
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const nav = useNavigate()
  const loc = useLocation() as { state?: { from?: string } }

  const go = async () => {
    if (!/^1\d{10}$/.test(phone)) {
      Toast.show({ content: '请输入正确手机号' })
      return
    }
    try {
      const data = await api('/auth/login', { method: 'POST', body: { phone, code } })
      setToken(data.token)
      nav(loc.state?.from ?? '/', { replace: true })
    } catch (e: any) {
      Toast.show({ content: e.message })
    }
  }

  return (
    <div style={{ maxWidth: 480, margin: '0 auto', padding: '80px 24px' }}>
      <div style={{ textAlign: 'center', marginBottom: 40 }}>
        <div style={{ fontSize: 26, fontWeight: 700 }}>素材任务</div>
        <div className="h5-muted" style={{ marginTop: 8 }}>
          发布素材 · 领取奖励
        </div>
      </div>
      <Input placeholder="手机号" type="tel" maxLength={11} value={phone} onChange={setPhone} clearable />
      <div style={{ height: 16 }} />
      <Input placeholder="验证码" type="number" maxLength={6} value={code} onChange={setCode} clearable />
      <Button block color="primary" size="large" style={{ marginTop: 32 }} onClick={go}>
        登录 / 注册
      </Button>
      <div className="h5-muted" style={{ textAlign: 'center', marginTop: 16 }}>
        测试环境验证码：123456
      </div>
    </div>
  )
}
