import { Button, Card, Form, Input, message } from 'antd'
import { useLocation, useNavigate } from 'react-router-dom'
import { api, setToken } from '../api'

export default function Login() {
  const nav = useNavigate()
  const loc = useLocation() as { state?: { from?: string } }
  const [form] = Form.useForm()

  const submit = async (v: { username: string; password: string }) => {
    try {
      const data = await api('/admin/auth/login', { method: 'POST', body: v })
      setToken(data.token)
      nav(loc.state?.from ?? '/', { replace: true })
    } catch (e: any) {
      message.error(e.message)
    }
  }

  return (
    <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh', background: '#f0f2f5' }}>
      <Card title="素材库 · 管理端登录" style={{ width: 380 }}>
        <Form form={form} onFinish={submit} layout="vertical">
          <Form.Item name="username" label="用户名" rules={[{ required: true }]}>
            <Input placeholder="admin" />
          </Form.Item>
          <Form.Item name="password" label="密码" rules={[{ required: true }]}>
            <Input.Password placeholder="admin123" />
          </Form.Item>
          <Button type="primary" htmlType="submit" block>
            登录
          </Button>
        </Form>
      </Card>
    </div>
  )
}
