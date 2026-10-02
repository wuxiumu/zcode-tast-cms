import { Layout, Menu } from 'antd'
import type { ReactElement } from 'react'
import { Navigate, Outlet, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { getToken } from './api'
import Dashboard from './pages/Dashboard'
import Login from './pages/Login'
import MaterialEdit from './pages/MaterialEdit'
import Materials from './pages/Materials'
import Reviews from './pages/Reviews'
import Rewards from './pages/Rewards'
import Taxonomy from './pages/Taxonomy'

const { Sider, Content, Header } = Layout

const menuItems = [
  { key: '/', label: '数据看板' },
  { key: '/materials', label: '素材管理' },
  { key: '/taxonomy', label: '分类与标签' },
  { key: '/reviews', label: '审核中心' },
  { key: '/rewards', label: '奖励记录' },
]

function Shell() {
  const nav = useNavigate()
  const loc = useLocation()
  const selected = loc.pathname.startsWith('/materials')
    ? '/materials'
    : loc.pathname.startsWith('/taxonomy')
      ? '/taxonomy'
      : loc.pathname.startsWith('/reviews')
        ? '/reviews'
        : loc.pathname.startsWith('/rewards')
          ? '/rewards'
          : '/'
  return (
    <Layout style={{ minHeight: '100vh' }}>
      {/* 侧边菜单固定不随页面滚动 */}
      <Sider style={{ overflow: 'auto', height: '100vh', position: 'fixed', left: 0, top: 0, bottom: 0 }}>
        <div style={{ color: '#fff', padding: 20, fontSize: 17, fontWeight: 600 }}>素材库 · 管理端</div>
        <Menu theme="dark" mode="inline" selectedKeys={[selected]} items={menuItems} onClick={(e) => nav(e.key)} />
      </Sider>
      <Layout style={{ marginLeft: 200 }}>
        <Header
          style={{
            position: 'sticky',
            top: 0,
            zIndex: 20,
            background: '#fff',
            display: 'flex',
            justifyContent: 'flex-end',
            alignItems: 'center',
            paddingInline: 24,
            borderBottom: '1px solid #f0f0f0',
          }}
        >
          <a
            onClick={() => {
              localStorage.removeItem('sc_admin_token')
              location.href = `${(import.meta.env.BASE_URL ?? '/admin/').replace(/\/$/, '')}/login`
            }}
          >
            退出登录
          </a>
        </Header>
        <Content style={{ padding: 24 }}>
          <Outlet />
        </Content>
      </Layout>
    </Layout>
  )
}

function RequireAuth({ children }: { children: ReactElement }) {
  const loc = useLocation()
  return getToken() ? children : <Navigate to="/login" state={{ from: loc.pathname }} replace />
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        element={
          <RequireAuth>
            <Shell />
          </RequireAuth>
        }
      >
        <Route path="/" element={<Dashboard />} />
        <Route path="/materials" element={<Materials />} />
        <Route path="/materials/new" element={<MaterialEdit />} />
        <Route path="/materials/:id/edit" element={<MaterialEdit />} />
        <Route path="/taxonomy" element={<Taxonomy />} />
        <Route path="/reviews" element={<Reviews />} />
        <Route path="/rewards" element={<Rewards />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
